<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\WriteActor;
use MyInvoice\Service\Client\ClientWriteException;
use MyInvoice\Service\Client\ClientWriter;
use PDO;
use PDOException;
use Throwable;

/**
 * Upsert klienta podle ReviziOR UUID (R3, §2.9 zadání).
 *
 * - identita je `revizior_client_links` (organization link + UUID); nikdy IČO
 *   ani e-mail — podobný klient jiného tenantu se nespojí;
 * - zápis jde přes sdílený {@see ClientWriter}, tedy stejnou validací,
 *   kontakty a auditem jako UI;
 * - stejný kanonický hash nebo starší `sourceUpdatedAt` = `unchanged` bez
 *   zápisu a bez audit noise;
 * - `null` u `city`/`postalCode`/`countryCode` znamená „ReviziOR to neví":
 *   u nového klienta zůstane pole prázdné (země = země dodavatele, stejný
 *   default jako formulář), u existujícího se hodnota doplněná ve fakturaci
 *   nepřepíše;
 * - `active=false` klienta archivuje, `true` odarchivuje; doklady zůstávají;
 * - link, klient, kontakty i audit jsou jedna transakce.
 */
final class ReviziorClientSynchronizer
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClientRepository $clients,
        private readonly ClientWriter $writer,
        private readonly ActivityLogger $activity,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly ReviziorClientRequestValidator $validator,
    ) {}

    /** @param array<string,mixed> $body */
    public function upsert(string $organizationUuid, string $clientUuid, array $body): ReviziorClientSyncResult
    {
        $input = $this->validator->validate($organizationUuid, $clientUuid, $body);
        $payloadHash = $this->hasher->hash($body);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $organization = $this->lockOrganization($pdo, $input['organizationUuid']);
            if ($organization === null) {
                throw ReviziorProvisioningException::notFound('organization_not_provisioned');
            }
            if ((string) $organization['status'] === 'suspended') {
                throw ReviziorProvisioningException::conflict('organization_suspended');
            }
            $organizationLinkId = (int) $organization['id'];
            $supplierId = (int) $organization['supplier_id'];

            $link = $this->lockClientLink($pdo, $organizationLinkId, $input['clientUuid']);
            if ($link !== null) {
                if ($this->isStale($input['sourceUpdatedAt'], $link['source_updated_at'])
                    || hash_equals((string) $link['payload_hash'], $payloadHash)
                ) {
                    $pdo->commit();
                    return $this->result($input['clientUuid'], (int) $link['client_id'], ReviziorClientSyncResult::UNCHANGED, (string) $link['payload_hash']);
                }
            }

            $countryIso2 = $input['countryCode'] ?? $this->supplierCountry($pdo, $supplierId);
            $this->assertCountryExists($pdo, $countryIso2);
            $actor = WriteActor::system();

            if ($link === null) {
                $client = $this->writer->create($supplierId, $this->createBody($input, $countryIso2), $actor, allowIncompleteAddress: true);
                $clientId = (int) $client['id'];
                $pdo->prepare(
                    'INSERT INTO revizior_client_links
                        (organization_link_id, client_uuid, client_id, payload_hash, source_updated_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
                )->execute([$organizationLinkId, $input['clientUuid'], $clientId, $payloadHash, $input['sourceUpdatedAt']]);
                $operation = ReviziorClientSyncResult::CREATED;
            } else {
                $clientId = (int) $link['client_id'];
                $current = $this->clients->find($clientId);
                if ($current === null || (int) $current['supplier_id'] !== $supplierId) {
                    // Link ukazuje na klienta cizího dodavatele — nesmí se stát,
                    // ale kdyby, integrace se ho nedotkne.
                    throw ReviziorProvisioningException::conflict('client_link_conflict');
                }
                $this->writer->update($clientId, $supplierId, $this->updateBody($input, $current), $actor, allowIncompleteAddress: true);
                $pdo->prepare(
                    'UPDATE revizior_client_links
                        SET payload_hash = ?, source_updated_at = ?, updated_at = UTC_TIMESTAMP(6)
                      WHERE id = ?'
                )->execute([$payloadHash, $input['sourceUpdatedAt'], (int) $link['id']]);
                $operation = ReviziorClientSyncResult::UPDATED;
            }

            $this->applyArchiveState($pdo, $clientId, $input['active']);

            $this->activity->log(
                'revizior.client.upserted',
                null,
                'client',
                $clientId,
                [
                    'organization_uuid' => $input['organizationUuid'],
                    'client_uuid' => $input['clientUuid'],
                    'operation' => $operation,
                    'payload_hash' => 'sha256:' . $payloadHash,
                ],
                supplierId: $supplierId,
            );

            $pdo->commit();
            return $this->result($input['clientUuid'], $clientId, $operation, $payloadHash);
        } catch (ClientWriteException $e) {
            $this->rollback($pdo);
            throw $this->translate($e);
        } catch (PDOException $e) {
            $this->rollback($pdo);
            if ($e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw ReviziorProvisioningException::conflict('client_link_conflict');
            }
            throw $e;
        } catch (Throwable $e) {
            $this->rollback($pdo);
            throw $e;
        }
    }

    /**
     * @param array{companyName:string,registrationNumber:?string,vatNumber:?string,street:string,city:?string,postalCode:?string,language:string,contacts:list<array{type:string,email:string,name:?string}>} $input
     * @return array<string,mixed>
     */
    private function createBody(array $input, string $countryIso2): array
    {
        return $this->ownedFields($input, $countryIso2) + [
            'city' => $input['city'] ?? '',
            'zip' => $input['postalCode'] ?? '',
            'is_customer' => 1,
        ];
    }

    /**
     * Existující klient: přepíší se jen pole, která ReviziOR vlastní a zná.
     * Všechno ostatní (měna, splatnost, kategorie, sazba, poznámka…) zůstává
     * tak, jak si to uživatel nastavil ve fakturaci — `ClientRepository::update`
     * je full-replace, takže se musí poslat celý současný řádek.
     *
     * @param array{companyName:string,registrationNumber:?string,vatNumber:?string,street:string,city:?string,postalCode:?string,language:string,contacts:list<array{type:string,email:string,name:?string}>} $input
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    private function updateBody(array $input, array $current, ?string $countryIso2 = null): array
    {
        $body = $current;
        unset($body['id'], $body['supplier_id'], $body['created_at'], $body['updated_at'], $body['archived_at'], $body['country_id'], $body['country_is_eu']);
        foreach ($this->ownedFields($input, $countryIso2 ?? (string) $current['country_iso2']) as $key => $value) {
            $body[$key] = $value;
        }
        if ($input['city'] !== null) {
            $body['city'] = $input['city'];
        }
        if ($input['postalCode'] !== null) {
            $body['zip'] = $input['postalCode'];
        }
        return $body;
    }

    /**
     * @param array{companyName:string,registrationNumber:?string,vatNumber:?string,street:string,language:string,contacts:list<array{type:string,email:string,name:?string}>} $input
     * @return array<string,mixed>
     */
    private function ownedFields(array $input, string $countryIso2): array
    {
        $billing = $input['contacts'][0] ?? null;
        return [
            'company_name' => $input['companyName'],
            'ic' => $input['registrationNumber'],
            'dic' => $input['vatNumber'],
            'street' => $input['street'],
            'country_iso2' => $countryIso2,
            'language' => $input['language'],
            'main_email' => $billing['email'] ?? null,
            'email_contacts' => array_map(
                static fn (array $contact): array => [
                    'email' => $contact['email'],
                    'contact_name' => $contact['name'],
                    'usages' => [
                        ['usage' => 'documents', 'recipient' => 'to'],
                        ['usage' => 'reminders', 'recipient' => 'to'],
                    ],
                ],
                $input['contacts'],
            ),
        ];
    }

    private function applyArchiveState(PDO $pdo, int $clientId, bool $active): void
    {
        $stmt = $pdo->prepare('SELECT archived_at FROM clients WHERE id = ? FOR UPDATE');
        $stmt->execute([$clientId]);
        $archivedAt = $stmt->fetchColumn();
        $isArchived = is_string($archivedAt) && $archivedAt !== '';
        if ($active && $isArchived) {
            $this->clients->unarchive($clientId);
        } elseif (!$active && !$isArchived) {
            $this->clients->archive($clientId);
        }
    }

    /** @return array<string,mixed>|null */
    private function lockOrganization(PDO $pdo, string $organizationUuid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, status FROM revizior_organization_links WHERE organization_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$organizationUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockClientLink(PDO $pdo, int $organizationLinkId, string $clientUuid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, client_id, payload_hash, source_updated_at
               FROM revizior_client_links
              WHERE organization_link_id = ? AND client_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$organizationLinkId, $clientUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function supplierCountry(PDO $pdo, int $supplierId): string
    {
        $stmt = $pdo->prepare('SELECT co.iso2 FROM supplier s JOIN countries co ON co.id = s.country_id WHERE s.id = ?');
        $stmt->execute([$supplierId]);
        $iso2 = $stmt->fetchColumn();
        return is_string($iso2) && $iso2 !== '' ? $iso2 : 'CZ';
    }

    private function assertCountryExists(PDO $pdo, string $iso2): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM countries WHERE iso2 = ?');
        $stmt->execute([$iso2]);
        if ($stmt->fetchColumn() === false) {
            throw ReviziorProvisioningException::clientValidation(['address.countryCode' => 'unknown_country']);
        }
    }

    private function isStale(string $sourceUpdatedAt, mixed $stored): bool
    {
        return is_string($stored) && $stored !== '' && strcmp($sourceUpdatedAt, $stored) < 0;
    }

    private function result(string $clientUuid, int $clientId, string $operation, string $payloadHash): ReviziorClientSyncResult
    {
        return new ReviziorClientSyncResult([
            'clientUuid' => $clientUuid,
            'externalClientId' => (string) $clientId,
            'operation' => $operation,
            'payloadHash' => 'sha256:' . $payloadHash,
        ]);
    }

    /**
     * Chyby sdílené služby mluví jazykem UI formuláře (`company_name`, `zip`);
     * kontrakt má vlastní názvy. Překlad je tady, ne v ClientWriteru — ten
     * o ReviziORu nic neví.
     */
    private function translate(ClientWriteException $e): ReviziorProvisioningException
    {
        return match ($e->kind) {
            ClientWriteException::KIND_VALIDATION => ReviziorProvisioningException::clientValidation(
                $this->translateFields($e->fields),
            ),
            ClientWriteException::KIND_CONTACTS => ReviziorProvisioningException::clientValidation(['contacts' => 'invalid_contacts']),
            ClientWriteException::KIND_NOT_FOUND => ReviziorProvisioningException::conflict('client_link_conflict'),
            default => ReviziorProvisioningException::clientValidation(['client' => 'integrity_violation']),
        };
    }

    /**
     * @param array<string, list<string>> $fields
     * @return array<string,string>
     */
    private function translateFields(array $fields): array
    {
        $map = [
            'company_name' => 'companyName',
            'ic' => 'registrationNumber',
            'dic' => 'vatNumber',
            'street' => 'address.street',
            'city' => 'address.city',
            'zip' => 'address.postalCode',
            'main_email' => 'contacts.0.email',
            'language' => 'language',
        ];
        $out = [];
        foreach ($fields as $field => $messages) {
            $out[$map[$field] ?? $field] = 'invalid_value';
        }
        return $out;
    }

    private function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
