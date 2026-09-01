<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use PDO;
use PDOException;
use Throwable;

final class ReviziorOrganizationProvisioner
{
    private const OPERATION = 'organization.provision';

    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $passwordHasher,
        private readonly ActivityLogger $activity,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly ReviziorProvisioningRequestValidator $validator,
    ) {}

    /** @param array<string,mixed> $body */
    public function provision(string $organizationUuid, array $body, string $idempotencyKey): ReviziorProvisioningResult
    {
        $idempotencyKey = trim($idempotencyKey);
        $input = $this->validator->validate($organizationUuid, $body, $idempotencyKey);
        $organizationUuid = (string) $input['organization']['uuid'];
        $requestHash = $this->hasher->hash($body);
        $keyHash = hash('sha256', $idempotencyKey);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->transactionalProvision($input, $requestHash, $keyHash);
            } catch (PDOException $e) {
                if ($attempt === 0 && $this->isUniqueConflict($e)) {
                    continue;
                }
                if ($this->isUniqueConflict($e)) {
                    throw ReviziorProvisioningException::conflict('organization_link_conflict');
                }
                throw $e;
            }
        }

        throw ReviziorProvisioningException::conflict('organization_link_conflict');
    }

    /**
     * @param array{organization:array<string,mixed>,owner:array<string,mixed>} $input
     */
    private function transactionalProvision(array $input, string $requestHash, string $keyHash): ReviziorProvisioningResult
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $organization = $input['organization'];
            $owner = $input['owner'];
            $organizationUuid = (string) $organization['uuid'];

            $insertIdempotency = $pdo->prepare(
                'INSERT INTO revizior_idempotency_keys
                    (subject_uuid, operation, key_hash, request_hash, state, created_at, expires_at)
                 VALUES (?, ?, ?, ?, \'pending\', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 10 YEAR)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $insertIdempotency->execute([$organizationUuid, self::OPERATION, $keyHash, $requestHash]);

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
                /** @var array<string,string> $data */
                return new ReviziorProvisioningResult($data, false);
            }

            $existing = $this->lockOrganization($pdo, $organizationUuid);
            if ($existing !== null) {
                if (!hash_equals((string) $existing['payload_hash'], $requestHash)) {
                    throw ReviziorProvisioningException::conflict('organization_link_conflict');
                }
                $data = $this->responseData($organizationUuid, (int) $existing['supplier_id'], $requestHash);
                $this->completeIdempotency($pdo, (int) $idempotency['id'], $data, (int) $existing['supplier_id']);
                $pdo->commit();
                return new ReviziorProvisioningResult($data, false);
            }

            $countryId = $this->countryId($pdo, (string) $organization['countryCode']);
            $defaultVatRateId = $this->defaultVatRateId($pdo);
            $supplierId = $this->insertSupplier($pdo, $organization, $owner, $countryId, $defaultVatRateId);
            $userId = $this->resolveOwnerUser($pdo, $owner, (string) $organization['language']);

            $link = $pdo->prepare(
                'INSERT INTO revizior_organization_links
                    (organization_uuid, supplier_id, status, onboarding_state, payload_hash,
                     source_updated_at, contract_version, created_at, updated_at)
                 VALUES (?, ?, \'onboarding\', \'incomplete\', ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            );
            $link->execute([
                $organizationUuid,
                $supplierId,
                $requestHash,
                $organization['sourceUpdatedAt'],
                ReviziorContract::VERSION,
            ]);
            $organizationLinkId = (int) $pdo->lastInsertId();

            $membership = $pdo->prepare(
                'INSERT INTO user_suppliers (user_id, supplier_id, role) VALUES (?, ?, \'supplier_owner\')'
            );
            $membership->execute([$userId, $supplierId]);

            $userLink = $pdo->prepare(
                'INSERT INTO revizior_user_links
                    (organization_link_id, user_uuid, user_id, supplier_role, active,
                     source_updated_at, session_version, created_at, updated_at)
                 VALUES (?, ?, ?, \'supplier_owner\', 1, ?, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            );
            $userLink->execute([
                $organizationLinkId,
                $owner['uuid'],
                $userId,
                $organization['sourceUpdatedAt'],
            ]);

            $this->activity->log(
                'revizior.organization.provisioned',
                null,
                'supplier',
                $supplierId,
                [
                    'organization_uuid' => $organizationUuid,
                    'owner_user_uuid' => $owner['uuid'],
                    'payload_hash' => 'sha256:' . $requestHash,
                ],
                supplierId: $supplierId,
            );

            $data = $this->responseData($organizationUuid, $supplierId, $requestHash);
            $this->completeIdempotency($pdo, (int) $idempotency['id'], $data, $supplierId);
            $pdo->commit();
            return new ReviziorProvisioningResult($data, true);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    private function lockIdempotency(PDO $pdo, string $organizationUuid, string $keyHash): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, request_hash, state, response_json
               FROM revizior_idempotency_keys
              WHERE subject_uuid = ? AND operation = ? AND key_hash = ?
              FOR UPDATE'
        );
        $stmt->execute([$organizationUuid, self::OPERATION, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockOrganization(PDO $pdo, string $organizationUuid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, payload_hash
               FROM revizior_organization_links
              WHERE organization_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$organizationUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $organization @param array<string,mixed> $owner */
    private function insertSupplier(
        PDO $pdo,
        array $organization,
        array $owner,
        int $countryId,
        int $defaultVatRateId,
    ): int {
        $bootstrapCurrencyId = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $foreignKeysDisabled = false;
        if ($bootstrapCurrencyId === 0) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $foreignKeysDisabled = true;
        }

        try {
            [$isVatPayer, $isIdentified] = match ($organization['vatStatus']) {
                'payer' => [1, 0],
                'identified_person' => [0, 1],
                default => [0, 0],
            };
            $stmt = $pdo->prepare(
                'INSERT INTO supplier
                    (company_name, display_name, street, city, zip, country_id, ic, dic,
                     is_vat_payer, is_identified, email, default_currency_id, default_vat_rate_id,
                     default_payment_due_days, default_payment_due_unit, default_hourly_rate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 14, \'days\', 1500.00)'
            );
            $stmt->execute([
                $organization['name'],
                $organization['name'],
                $organization['street'],
                $organization['city'],
                $organization['postalCode'],
                $countryId,
                $organization['registrationNumber'],
                $organization['vatNumber'],
                $isVatPayer,
                $isIdentified,
                $owner['email'],
                $bootstrapCurrencyId,
                $defaultVatRateId,
            ]);
            $supplierId = (int) $pdo->lastInsertId();

            $currency = $pdo->prepare(
                'INSERT INTO currencies
                    (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
            );
            $currency->execute([$supplierId, 'CZK', 'CZK — výchozí', 'Kč', 'Česká koruna', 'Czech Koruna']);
            $defaultCurrencyId = (int) $pdo->lastInsertId();
            $currency->execute([$supplierId, 'EUR', 'EUR — výchozí', '€', 'Euro', 'Euro']);

            $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')
                ->execute([$defaultCurrencyId, $supplierId]);
            return $supplierId;
        } finally {
            if ($foreignKeysDisabled) $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @param array<string,mixed> $owner */
    private function resolveOwnerUser(PDO $pdo, array $owner, string $locale): int
    {
        $stmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE email = ? FOR UPDATE');
        $stmt->execute([$owner['email']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if ((string) $existing['role'] === 'admin' || !(bool) $existing['is_active']) {
                throw ReviziorProvisioningException::conflict('user_link_conflict');
            }
            return (int) $existing['id'];
        }

        $password = bin2hex(random_bytes(32));
        $insert = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, \'readonly\', ?, 1)'
        );
        $insert->execute([
            $owner['email'],
            $this->passwordHasher->hash($password),
            $owner['name'],
            $locale,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function countryId(PDO $pdo, string $countryCode): int
    {
        $stmt = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?');
        $stmt->execute([$countryCode]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id === 0) {
            throw ReviziorProvisioningException::validation([
                'organization.address.countryCode' => 'unknown_country',
            ]);
        }
        return $id;
    }

    private function defaultVatRateId(PDO $pdo): int
    {
        $id = (int) $pdo->query('SELECT id FROM vat_rates WHERE is_default = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === 0) $id = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === 0) throw new \RuntimeException('Tabulka vat_rates je prázdná.');
        return $id;
    }

    /** @param array<string,string> $data */
    private function completeIdempotency(PDO $pdo, int $id, array $data, int $supplierId): void
    {
        $stmt = $pdo->prepare(
            'UPDATE revizior_idempotency_keys
                SET state = \'completed\', http_status = 201, response_json = ?,
                    resource_type = \'supplier\', resource_id = ?, completed_at = UTC_TIMESTAMP(6)
              WHERE id = ?'
        );
        $stmt->execute([
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string) $supplierId,
            $id,
        ]);
    }

    /** @return array<string,string> */
    private function responseData(string $organizationUuid, int $supplierId, string $requestHash): array
    {
        return [
            'organizationUuid' => $organizationUuid,
            'supplierId' => (string) $supplierId,
            'status' => 'onboarding',
            'onboardingState' => 'incomplete',
            'configurePath' => '/settings/supplier',
            'payloadHash' => 'sha256:' . $requestHash,
        ];
    }

    private function isUniqueConflict(PDOException $e): bool
    {
        return $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
