<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceDraftCreator;
use MyInvoice\Service\Invoice\InvoiceDraftException;
use MyInvoice\Service\WriteActor;
use PDO;
use PDOException;
use Throwable;

/**
 * Idempotentní koncept dokladu pro ReviziOR (§2.11 zadání) a jeho čtení (§2.13).
 *
 * Jedna transakce: idempotency záznam → organization link → client link →
 * `InvoiceDraftCreator` (stejná cesta jako UI: defaulty, validace, výpočet,
 * kurz, audit) → `revizior_invoice_links` + `revizior_invoice_sources` →
 * uložená odpověď. Nikdy nevznikne „doklad existuje, vazba ne“.
 *
 * - stejný `Idempotency-Key` + stejný payload → uložená odpověď (`200`);
 * - stejný key + jiný payload → `409 idempotency_conflict`;
 * - jiný key, ale stejný `externalInvoiceKey` → `409 invoice_link_conflict`
 *   (jeden ReviziOR klíč = nejvýš jeden doklad);
 * - částky se neinterpretují — decimal stringy jdou do MyInvoice, který
 *   validuje a počítá; sazba DPH se překládá z procenta na platnou tuzemskou
 *   sazbu k DUZP, jinak `invoice_validation_failed`.
 */
final class ReviziorInvoiceDraftService
{
    private const OPERATION = 'invoice-draft';

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceDraftCreator $creator,
        private readonly ReviziorInvoiceSnapshotBuilder $snapshots,
        private readonly ActivityLogger $activity,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly ReviziorInvoiceDraftRequestValidator $validator,
        private readonly ReviziorInvoiceEventPublisher $events,
    ) {}

    /** @param array<string,mixed> $body */
    public function create(string $organizationUuid, array $body, string $idempotencyKey): ReviziorInvoiceDraftResult
    {
        $input = $this->validator->validate($organizationUuid, $body, $idempotencyKey);
        $requestHash = $this->hasher->hash($body);
        $keyHash = hash('sha256', $idempotencyKey);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->transactionalCreate($input, $requestHash, $keyHash);
            } catch (PDOException $e) {
                if ($attempt === 0 && $this->isUniqueConflict($e)) {
                    continue;
                }
                if ($this->isUniqueConflict($e)) {
                    throw ReviziorProvisioningException::conflict('invoice_link_conflict');
                }
                throw $e;
            }
        }

        throw ReviziorProvisioningException::conflict('invoice_link_conflict');
    }

    /** @return array<string,mixed> */
    public function snapshot(string $organizationUuid, string $externalInvoiceKey): array
    {
        $key = $this->validator->validateKey($organizationUuid, $externalInvoiceKey);
        $pdo = $this->db->pdo();
        $organization = $this->organization($pdo, $key['organizationUuid'], lock: false);
        if ($organization === null) {
            throw ReviziorProvisioningException::notFound('organization_not_provisioned');
        }
        $link = $this->invoiceLink($pdo, (int) $organization['id'], $key['externalInvoiceKey'], lock: false);
        if ($link === null) {
            throw ReviziorProvisioningException::notFound('invoice_not_found');
        }
        $invoice = $this->invoices->find((int) $link['invoice_id']);
        if ($invoice === null || (int) $invoice['supplier_id'] !== (int) $organization['supplier_id']) {
            throw ReviziorProvisioningException::notFound('invoice_not_found');
        }
        return $this->snapshots->build($invoice, $key['externalInvoiceKey'], (int) $link['event_sequence']);
    }

    /** @param array<string,mixed> $input */
    private function transactionalCreate(array $input, string $requestHash, string $keyHash): ReviziorInvoiceDraftResult
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $organizationUuid = (string) $input['organizationUuid'];
            $pdo->prepare(
                'INSERT INTO revizior_idempotency_keys
                    (subject_uuid, operation, key_hash, request_hash, state, created_at, expires_at)
                 VALUES (?, ?, ?, ?, \'pending\', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 10 YEAR)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            )->execute([$organizationUuid, self::OPERATION, $keyHash, $requestHash]);

            $idempotency = $this->lockIdempotency($pdo, $organizationUuid, $keyHash);
            if ($idempotency === null) {
                throw new \RuntimeException('Idempotency row se nepodařilo uzamknout.');
            }
            if (!hash_equals((string) $idempotency['request_hash'], $requestHash)) {
                throw ReviziorProvisioningException::conflict('idempotency_conflict');
            }
            if ((string) $idempotency['state'] === 'completed') {
                $data = json_decode((string) $idempotency['response_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($data)) throw new \RuntimeException('Uložená idempotentní odpověď není platná.');
                $pdo->commit();
                return new ReviziorInvoiceDraftResult($data, false);
            }

            $organization = $this->organization($pdo, $organizationUuid, lock: true);
            if ($organization === null) {
                throw ReviziorProvisioningException::notFound('organization_not_provisioned');
            }
            if ((string) $organization['status'] === 'suspended') {
                throw ReviziorProvisioningException::conflict('organization_suspended');
            }
            $organizationLinkId = (int) $organization['id'];
            $supplierId = (int) $organization['supplier_id'];

            $existing = $this->invoiceLink($pdo, $organizationLinkId, (string) $input['externalInvoiceKey'], lock: true);
            if ($existing !== null) {
                // Jiný Idempotency-Key pro už založený doklad: stejný payload je
                // bezpečné opakování, jiný je pokus založit druhý doklad pod týmž klíčem.
                if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                    throw ReviziorProvisioningException::conflict('invoice_link_conflict');
                }
                $invoice = $this->invoices->find((int) $existing['invoice_id']);
                if ($invoice === null) throw new \RuntimeException('Doklad k existující vazbě chybí.');
                $data = $this->snapshots->build($invoice, (string) $input['externalInvoiceKey'], (int) $existing['event_sequence']);
                $this->completeIdempotency($pdo, (int) $idempotency['id'], 200, $data, (int) $existing['invoice_id']);
                $pdo->commit();
                return new ReviziorInvoiceDraftResult($data, false);
            }

            $client = $this->clientLink($pdo, $organizationLinkId, (string) $input['clientUuid']);
            if ($client === null) {
                throw ReviziorProvisioningException::notFound('client_not_linked');
            }
            if ((int) $client['supplier_id'] !== $supplierId) {
                throw ReviziorProvisioningException::conflict('client_link_conflict');
            }

            $body = $this->draftBody($pdo, $input, (int) $client['client_id']);
            try {
                $created = $this->creator->create($supplierId, $body, new WriteActor($this->createdBy($pdo, $organizationLinkId)));
            } catch (InvoiceDraftException $e) {
                throw $this->translate($e);
            }
            $invoice = $created['invoice'];
            $invoiceId = (int) $invoice['id'];

            $pdo->prepare(
                'INSERT INTO revizior_invoice_links
                    (organization_link_id, external_invoice_key, invoice_id, request_hash, event_sequence, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            )->execute([$organizationLinkId, $input['externalInvoiceKey'], $invoiceId, $requestHash]);
            $invoiceLinkId = (int) $pdo->lastInsertId();
            $this->insertSources($pdo, $invoiceLinkId, $input['items']);

            // Událost vzniká ve stejné transakci jako doklad (R5) a posune
            // `event_sequence` na 1 — snapshot v odpovědi ho proto čte znovu.
            $this->events->publish($invoiceId, ReviziorInvoiceEventPublisher::TYPE_DRAFT_CREATED, $invoice);
            $data = $this->snapshots->build($invoice, (string) $input['externalInvoiceKey'], $this->sequence($pdo, $invoiceLinkId));
            $this->completeIdempotency($pdo, (int) $idempotency['id'], 201, $data, $invoiceId);

            $this->activity->log(
                'revizior.invoice.draft_created',
                null,
                'invoice',
                $invoiceId,
                [
                    'organization_uuid' => $organizationUuid,
                    'external_invoice_key' => $input['externalInvoiceKey'],
                    'client_uuid' => $input['clientUuid'],
                    'request_hash' => 'sha256:' . $requestHash,
                ],
                supplierId: $supplierId,
            );

            $pdo->commit();
            return new ReviziorInvoiceDraftResult($data, true);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Překlad kontraktu na tělo, kterému rozumí `InvoiceDraftCreator`.
     *
     * Měna jde kódem — `InvoiceDefaults` ji přeloží na id v rámci dodavatele
     * a odmítne cizí. Sazba DPH: procento → platná tuzemská sazba k DUZP
     * (bez reverse charge — ten se řídí klientem, ne řádkem).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function draftBody(PDO $pdo, array $input, int $clientId): array
    {
        $taxDate = $input['invoiceType'] === 'proforma' ? null : ($input['taxDate'] ?? $input['issueDate']);
        $rateDate = $taxDate ?? $input['issueDate'];
        $fields = [];
        $items = [];
        foreach ($input['items'] as $i => $item) {
            $vatRateId = $this->vatRateId($pdo, (string) $item['vatRate'], (string) $rateDate);
            if ($vatRateId === null) {
                $fields["items.{$i}.vatRate"] = 'unknown_vat_rate';
                continue;
            }
            $items[] = [
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price_without_vat' => $item['unitPrice'],
                'vat_rate_id' => $vatRateId,
                'order_index' => $i,
            ];
        }
        if ($fields !== []) {
            throw ReviziorProvisioningException::invoiceValidation($fields);
        }

        return [
            'client_id' => $clientId,
            'invoice_type' => $input['invoiceType'],
            'currency' => $input['currency'],
            'issue_date' => $input['issueDate'],
            'tax_date' => $taxDate,
            'due_date' => $input['dueDate'],
            'prices_include_vat' => $input['pricesIncludeVat'],
            'language' => $input['language'],
            'items' => $items,
        ];
    }

    /**
     * `invoices.created_by` je povinná FK na uživatele. Kontrakt v1 nenese, kdo
     * v ReviziORu koncept vyvolal, takže se doklad připíše vlastníkovi tenantu
     * (`supplier_owner` s aktivním linkem), případně jinému aktivnímu členovi.
     * Bez členství nejde doklad vytvořit — a ani by ho neměl kdo otevřít.
     */
    private function createdBy(PDO $pdo, int $organizationLinkId): int
    {
        $stmt = $pdo->prepare(
            'SELECT rul.user_id FROM revizior_user_links rul
               JOIN users u ON u.id = rul.user_id
              WHERE rul.organization_link_id = ? AND rul.active = 1 AND u.is_active = 1
              ORDER BY (rul.supplier_role = \'supplier_owner\') DESC, rul.id ASC LIMIT 1'
        );
        $stmt->execute([$organizationLinkId]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw ReviziorProvisioningException::conflict('user_membership_inactive');
        }
        return (int) $userId;
    }

    private function vatRateId(PDO $pdo, string $percent, string $date): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM vat_rates
              WHERE rate_percent = ? AND country = \'CZ\' AND is_reverse_charge = 0
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([number_format((float) $percent, 2, '.', ''), $date, $date]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param list<array<string,mixed>> $items */
    private function insertSources(PDO $pdo, int $invoiceLinkId, array $items): void
    {
        $insert = $pdo->prepare(
            'INSERT INTO revizior_invoice_sources
                (invoice_link_id, source_type, source_uuid, external_line_key, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE id = id'
        );
        foreach ($items as $item) {
            $metadata = json_encode([
                'description' => mb_substr((string) $item['description'], 0, 190),
                'price_list_code' => $item['priceListCode'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            foreach ($item['sourceReferences'] as $source) {
                $insert->execute([$invoiceLinkId, $source['type'], $source['uuid'], $item['externalLineKey'], $metadata]);
            }
        }
    }

    private function sequence(PDO $pdo, int $invoiceLinkId): int
    {
        $statement = $pdo->prepare('SELECT event_sequence FROM revizior_invoice_links WHERE id = ?');
        $statement->execute([$invoiceLinkId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function organization(PDO $pdo, string $organizationUuid, bool $lock): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, status FROM revizior_organization_links WHERE organization_uuid = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$organizationUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function invoiceLink(PDO $pdo, int $organizationLinkId, string $externalInvoiceKey, bool $lock): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, invoice_id, request_hash, event_sequence
               FROM revizior_invoice_links
              WHERE organization_link_id = ? AND external_invoice_key = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$organizationLinkId, $externalInvoiceKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function clientLink(PDO $pdo, int $organizationLinkId, string $clientUuid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT rcl.client_id, c.supplier_id
               FROM revizior_client_links rcl
               JOIN clients c ON c.id = rcl.client_id
              WHERE rcl.organization_link_id = ? AND rcl.client_uuid = ?'
        );
        $stmt->execute([$organizationLinkId, $clientUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockIdempotency(PDO $pdo, string $organizationUuid, string $keyHash): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, request_hash, state, response_json
               FROM revizior_idempotency_keys
              WHERE subject_uuid = ? AND operation = ? AND key_hash = ? FOR UPDATE'
        );
        $stmt->execute([$organizationUuid, self::OPERATION, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    private function completeIdempotency(PDO $pdo, int $id, int $httpStatus, array $data, int $invoiceId): void
    {
        $pdo->prepare(
            'UPDATE revizior_idempotency_keys
                SET state = \'completed\', http_status = ?, response_json = ?,
                    resource_type = \'invoice\', resource_id = ?, completed_at = UTC_TIMESTAMP(6)
              WHERE id = ?'
        )->execute([
            $httpStatus,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string) $invoiceId,
            $id,
        ]);
    }

    /**
     * Chyby sdílené služby mluví jazykem UI (`items.0.unit_price_without_vat`);
     * kontrakt má vlastní názvy.
     */
    private function translate(InvoiceDraftException $e): ReviziorProvisioningException
    {
        return match ($e->kind) {
            InvoiceDraftException::KIND_VALIDATION => ReviziorProvisioningException::invoiceValidation($this->translateFields($e->fields)),
            InvoiceDraftException::KIND_CLIENT_NOT_FOUND => ReviziorProvisioningException::conflict('client_link_conflict'),
            InvoiceDraftException::KIND_VARSYMBOL_DUPLICATE => ReviziorProvisioningException::conflict('invoice_link_conflict'),
            default => ReviziorProvisioningException::invoiceValidation(['invoice' => 'integrity_violation']),
        };
    }

    /**
     * @param array<string, list<string>> $fields
     * @return array<string,string>
     */
    private function translateFields(array $fields): array
    {
        $map = [
            'client_id' => 'clientUuid',
            'currency_id' => 'currency',
            'issue_date' => 'issueDate',
            'due_date' => 'dueDate',
            'tax_date' => 'taxDate',
            'invoice_type' => 'invoiceType',
            'unit_price_without_vat' => 'unitPrice',
            'vat_rate_id' => 'vatRate',
        ];
        $out = [];
        foreach (array_keys($fields) as $field) {
            $translated = (string) $field;
            foreach ($map as $internal => $external) {
                $translated = preg_replace('/(^|\.)' . preg_quote($internal, '/') . '$/', '$1' . $external, $translated) ?? $translated;
            }
            $out[$translated] = 'invalid_value';
        }
        return $out;
    }

    private function isUniqueConflict(PDOException $e): bool
    {
        // Jen duplicitní klíč (1062). Obecné 23000 kryje i FK/NOT NULL porušení,
        // a to není konflikt idempotence, ale chyba, která má být vidět.
        return (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
