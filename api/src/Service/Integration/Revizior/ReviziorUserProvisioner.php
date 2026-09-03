<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use PDO;
use PDOException;
use Throwable;

final class ReviziorUserProvisioner
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $passwordHasher,
        private readonly ActivityLogger $activity,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly ReviziorUserRequestValidator $validator,
    ) {}

    /** @param array<string,mixed> $body */
    public function upsert(string $organizationUuid, string $userUuid, array $body): ReviziorUserProvisioningResult
    {
        $input = $this->validator->validate($organizationUuid, $userUuid, $body);
        $payloadHash = $this->hasher->hash($body);

        try {
            return $this->transactionalUpsert($input, $payloadHash);
        } catch (PDOException $e) {
            if ($this->isUniqueConflict($e)) {
                throw ReviziorProvisioningException::conflict('user_link_conflict');
            }
            throw $e;
        }
    }

    public function revoke(string $organizationUuid, string $userUuid): void
    {
        $path = $this->validator->validatePath($organizationUuid, $userUuid);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $organization = $this->lockOrganization($pdo, $path['organizationUuid']);
            if ($organization === null) {
                throw ReviziorProvisioningException::notFound('organization_not_provisioned');
            }
            $link = $this->lockUserLink($pdo, (int) $organization['id'], $path['userUuid']);
            if ($link === null || !(bool) $link['active']) {
                $pdo->commit();
                return;
            }

            $pdo->prepare('DELETE FROM user_suppliers WHERE user_id = ? AND supplier_id = ?')
                ->execute([(int) $link['user_id'], (int) $organization['supplier_id']]);
            $pdo->prepare(
                'UPDATE revizior_user_links
                    SET active = 0, session_version = session_version + 1,
                        revoked_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
                  WHERE id = ?'
            )->execute([(int) $link['id']]);

            $this->activity->log(
                'revizior.user.revoked',
                null,
                'user',
                (int) $link['user_id'],
                [
                    'organization_uuid' => $path['organizationUuid'],
                    'user_uuid' => $path['userUuid'],
                ],
                supplierId: (int) $organization['supplier_id'],
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array{organizationUuid:string,userUuid:string,email:string,name:string,role:string,active:bool,sourceUpdatedAt:string} $input
     */
    private function transactionalUpsert(array $input, string $payloadHash): ReviziorUserProvisioningResult
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $organization = $this->lockOrganization($pdo, $input['organizationUuid']);
            if ($organization === null) {
                throw ReviziorProvisioningException::notFound('organization_not_provisioned');
            }
            if ($input['active'] && (string) $organization['status'] === 'suspended') {
                throw ReviziorProvisioningException::conflict('organization_suspended');
            }
            $organizationLinkId = (int) $organization['id'];
            $supplierId = (int) $organization['supplier_id'];
            $link = $this->lockUserLink($pdo, $organizationLinkId, $input['userUuid']);

            if ($link !== null && $this->isStale($input['sourceUpdatedAt'], $link['source_updated_at'])) {
                $data = $this->responseData(
                    (int) $link['user_id'],
                    (string) $link['supplier_role'],
                    (bool) $link['active'],
                );
                $pdo->commit();
                return new ReviziorUserProvisioningResult($data, false);
            }

            $created = $link === null;
            $userId = $link === null
                ? $this->resolveOrCreateUser($pdo, $organizationLinkId, $input)
                : (int) $link['user_id'];
            $this->updateUserIdentity($pdo, $userId, $input);

            if ($link === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO revizior_user_links
                        (organization_link_id, user_uuid, user_id, supplier_role, payload_hash,
                         active, source_updated_at, session_version, created_at, updated_at, revoked_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6),
                             IF(? = 1, NULL, UTC_TIMESTAMP(6)))'
                );
                $insert->execute([
                    $organizationLinkId,
                    $input['userUuid'],
                    $userId,
                    $input['role'],
                    $payloadHash,
                    (int) $input['active'],
                    $input['sourceUpdatedAt'],
                    (int) $input['active'],
                ]);
                $changed = true;
            } else {
                $membershipChanged = (bool) $link['active'] !== $input['active']
                    || ($input['active'] && (string) $link['supplier_role'] !== $input['role']);
                $changed = !is_string($link['payload_hash'])
                    || !hash_equals($link['payload_hash'], $payloadHash)
                    || $membershipChanged;

                $update = $pdo->prepare(
                    'UPDATE revizior_user_links
                        SET supplier_role = ?, payload_hash = ?, active = ?, source_updated_at = ?,
                            session_version = session_version + ?,
                            revoked_at = IF(? = 1, NULL, COALESCE(revoked_at, UTC_TIMESTAMP(6))),
                            updated_at = UTC_TIMESTAMP(6)
                      WHERE id = ?'
                );
                $update->execute([
                    $input['role'],
                    $payloadHash,
                    (int) $input['active'],
                    $input['sourceUpdatedAt'],
                    (int) $membershipChanged,
                    (int) $input['active'],
                    (int) $link['id'],
                ]);
            }

            if ($input['active']) {
                $pdo->prepare(
                    'INSERT INTO user_suppliers (user_id, supplier_id, role) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE role = VALUES(role)'
                )->execute([$userId, $supplierId, $input['role']]);
            } else {
                $pdo->prepare('DELETE FROM user_suppliers WHERE user_id = ? AND supplier_id = ?')
                    ->execute([$userId, $supplierId]);
            }

            if ($changed) {
                $this->activity->log(
                    $input['active'] ? 'revizior.user.upserted' : 'revizior.user.revoked',
                    null,
                    'user',
                    $userId,
                    [
                        'organization_uuid' => $input['organizationUuid'],
                        'user_uuid' => $input['userUuid'],
                        'role' => $input['role'],
                        'payload_hash' => 'sha256:' . $payloadHash,
                    ],
                    supplierId: $supplierId,
                );
            }

            $data = $this->responseData($userId, $input['role'], $input['active']);
            $pdo->commit();
            return new ReviziorUserProvisioningResult($data, $created);
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
            'SELECT id, supplier_id, status
               FROM revizior_organization_links
              WHERE organization_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$organizationUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lockUserLink(PDO $pdo, int $organizationLinkId, string $userUuid): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, user_id, supplier_role, payload_hash, active, source_updated_at, session_version
               FROM revizior_user_links
              WHERE organization_link_id = ? AND user_uuid = ? FOR UPDATE'
        );
        $stmt->execute([$organizationLinkId, $userUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array{organizationUuid:string,userUuid:string,email:string,name:string,role:string,active:bool,sourceUpdatedAt:string} $input
     */
    private function resolveOrCreateUser(PDO $pdo, int $organizationLinkId, array $input): int
    {
        $stmt = $pdo->prepare('SELECT id, role, is_active FROM users WHERE email = ? FOR UPDATE');
        $stmt->execute([$input['email']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if ((string) $existing['role'] === 'admin' || !(bool) $existing['is_active']) {
                throw ReviziorProvisioningException::conflict('user_link_conflict');
            }
            $collision = $pdo->prepare(
                'SELECT id FROM revizior_user_links
                  WHERE organization_link_id = ? AND user_id = ? FOR UPDATE'
            );
            $collision->execute([$organizationLinkId, (int) $existing['id']]);
            if ($collision->fetchColumn() !== false) {
                throw ReviziorProvisioningException::conflict('user_link_conflict');
            }
            return (int) $existing['id'];
        }

        $password = bin2hex(random_bytes(32));
        $insert = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, ?, \'readonly\', \'cs\', 1)'
        );
        $insert->execute([
            $input['email'],
            $this->passwordHasher->hash($password),
            $input['name'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{organizationUuid:string,userUuid:string,email:string,name:string,role:string,active:bool,sourceUpdatedAt:string} $input
     */
    private function updateUserIdentity(PDO $pdo, int $userId, array $input): void
    {
        $user = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        $row = $user->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (string) $row['role'] === 'admin' || ($input['active'] && !(bool) $row['is_active'])) {
            throw ReviziorProvisioningException::conflict('user_link_conflict');
        }

        $collision = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? FOR UPDATE');
        $collision->execute([$input['email'], $userId]);
        if ($collision->fetchColumn() !== false) {
            throw ReviziorProvisioningException::conflict('user_link_conflict');
        }

        $pdo->prepare('UPDATE users SET email = ?, name = ? WHERE id = ?')
            ->execute([$input['email'], $input['name'], $userId]);
    }

    private function isStale(string $sourceUpdatedAt, mixed $storedSourceUpdatedAt): bool
    {
        return is_string($storedSourceUpdatedAt)
            && $storedSourceUpdatedAt !== ''
            && strcmp($sourceUpdatedAt, $storedSourceUpdatedAt) < 0;
    }

    /** @return array{externalUserId:string,role:string,active:bool} */
    private function responseData(int $userId, string $role, bool $active): array
    {
        return [
            'externalUserId' => (string) $userId,
            'role' => $role,
            'active' => $active,
        ];
    }

    private function isUniqueConflict(PDOException $e): bool
    {
        return $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
