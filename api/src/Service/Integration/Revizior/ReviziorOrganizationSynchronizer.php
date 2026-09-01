<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;
use Throwable;

final class ReviziorOrganizationSynchronizer
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly ReviziorOrganizationUpdateRequestValidator $validator,
    ) {}

    /** @param array<string,mixed> $body */
    public function synchronize(string $organizationUuid, array $body): ReviziorProvisioningResult
    {
        $input = $this->validator->validate($organizationUuid, $body);
        $payloadHash = $this->hasher->hash($body);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $link = $this->lockOrganization($pdo, $input['uuid']);
            if ($link === null) {
                throw ReviziorProvisioningException::notFound('organization_not_provisioned');
            }

            if ($this->isStale($input['sourceUpdatedAt'], $link['source_updated_at'])) {
                $data = $this->responseData($input['uuid'], $link, (string) $link['payload_hash']);
                $pdo->commit();
                return new ReviziorProvisioningResult($data, false);
            }

            $countryId = $this->countryId($pdo, $input['countryCode']);
            [$isVatPayer, $isIdentified] = match ($input['vatStatus']) {
                'payer' => [1, 0],
                'identified_person' => [0, 1],
                default => [0, 0],
            };
            $pdo->prepare(
                'UPDATE supplier
                    SET company_name = ?, display_name = ?, street = ?, city = ?, zip = ?,
                        country_id = ?, ic = ?, dic = ?, is_vat_payer = ?, is_identified = ?
                  WHERE id = ?'
            )->execute([
                $input['name'],
                $input['name'],
                $input['street'],
                $input['city'],
                $input['postalCode'],
                $countryId,
                $input['registrationNumber'],
                $input['vatNumber'],
                $isVatPayer,
                $isIdentified,
                (int) $link['supplier_id'],
            ]);

            $status = $input['active']
                ? ((string) $link['onboarding_state'] === 'completed' ? 'active' : 'onboarding')
                : 'suspended';
            $statusChanged = (string) $link['status'] !== $status;
            $changed = !hash_equals((string) $link['payload_hash'], $payloadHash)
                || $statusChanged;
            $pdo->prepare(
                'UPDATE revizior_organization_links
                    SET status = ?, payload_hash = ?, source_updated_at = ?,
                        suspended_at = IF(? = \'suspended\', COALESCE(suspended_at, UTC_TIMESTAMP(6)), NULL),
                        updated_at = UTC_TIMESTAMP(6)
                  WHERE id = ?'
            )->execute([
                $status,
                $payloadHash,
                $input['sourceUpdatedAt'],
                $status,
                (int) $link['id'],
            ]);

            if ($statusChanged && $status === 'suspended') {
                $pdo->prepare(
                    'DELETE us FROM user_suppliers us
                      JOIN revizior_user_links rul
                        ON rul.user_id = us.user_id AND rul.organization_link_id = ?
                     WHERE us.supplier_id = ?'
                )->execute([(int) $link['id'], (int) $link['supplier_id']]);
                $pdo->prepare(
                    'UPDATE revizior_user_links
                        SET session_version = session_version + 1, updated_at = UTC_TIMESTAMP(6)
                      WHERE organization_link_id = ? AND active = 1'
                )->execute([(int) $link['id']]);
            } elseif ($statusChanged && (string) $link['status'] === 'suspended') {
                $pdo->prepare(
                    'INSERT INTO user_suppliers (user_id, supplier_id, role)
                     SELECT user_id, ?, supplier_role
                       FROM revizior_user_links
                      WHERE organization_link_id = ? AND active = 1
                     ON DUPLICATE KEY UPDATE role = VALUES(role)'
                )->execute([(int) $link['supplier_id'], (int) $link['id']]);
                $pdo->prepare(
                    'UPDATE revizior_user_links
                        SET session_version = session_version + 1, updated_at = UTC_TIMESTAMP(6)
                      WHERE organization_link_id = ? AND active = 1'
                )->execute([(int) $link['id']]);
            }

            if ($changed) {
                $this->activity->log(
                    'revizior.organization.updated',
                    null,
                    'supplier',
                    (int) $link['supplier_id'],
                    [
                        'organization_uuid' => $input['uuid'],
                        'status' => $status,
                        'payload_hash' => 'sha256:' . $payloadHash,
                    ],
                    supplierId: (int) $link['supplier_id'],
                );
            }

            $link['status'] = $status;
            $data = $this->responseData($input['uuid'], $link, $payloadHash);
            $pdo->commit();
            return new ReviziorProvisioningResult($data, false);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    private function lockOrganization(PDO $pdo, string $organizationUuid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, supplier_id, status, onboarding_state, payload_hash, source_updated_at
               FROM revizior_organization_links
              WHERE organization_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$organizationUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
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

    private function isStale(string $sourceUpdatedAt, mixed $storedSourceUpdatedAt): bool
    {
        return is_string($storedSourceUpdatedAt)
            && $storedSourceUpdatedAt !== ''
            && strcmp($sourceUpdatedAt, $storedSourceUpdatedAt) < 0;
    }

    /** @param array<string,mixed> $link @return array<string,string> */
    private function responseData(string $organizationUuid, array $link, string $payloadHash): array
    {
        return [
            'organizationUuid' => $organizationUuid,
            'supplierId' => (string) $link['supplier_id'],
            'status' => (string) $link['status'],
            'onboardingState' => (string) $link['onboarding_state'],
            'configurePath' => '/settings/supplier',
            'payloadHash' => 'sha256:' . $payloadHash,
        ];
    }
}
