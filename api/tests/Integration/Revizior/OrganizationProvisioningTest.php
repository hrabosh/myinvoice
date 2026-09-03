<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\Revizior\CanonicalPayloadHasher;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use MyInvoice\Service\Integration\Revizior\ReviziorUserProvisioner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class OrganizationProvisioningTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000001';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000001';
    private const OWNER_EMAIL = 'revizior-provisioning-owner@example.invalid';
    private const USER_UUID = '29000000-0000-4000-8000-000000000002';
    private const USER_EMAIL = 'revizior-provisioning-accountant@example.invalid';

    private Connection $db;
    private ReviziorOrganizationProvisioner $provisioner;
    private ReviziorOrganizationSynchronizer $organizationSynchronizer;
    private ReviziorUserProvisioner $userProvisioner;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $this->organizationSynchronizer = $container->get(ReviziorOrganizationSynchronizer::class);
            $this->userProvisioner = $container->get(ReviziorUserProvisioner::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        foreach (['revizior_organization_links', 'revizior_user_links', 'revizior_idempotency_keys'] as $table) {
            if ($this->db->pdo()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn() === false) {
                $this->markTestSkipped("Tabulka {$table} chybí — spusť api/bin/migrate.php.");
            }
        }
        if ($this->db->pdo()->query("SHOW COLUMNS FROM revizior_user_links LIKE 'payload_hash'")->fetchColumn() === false) {
            $this->markTestSkipped('Migrace 0153_revizior_user_provisioning.sql chybí.');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testProvisioningIsAtomicIdempotentAndCreatesTenantOwner(): void
    {
        $body = $this->body();
        $key = 'provision:' . self::ORGANIZATION_UUID . ':v1';
        $created = $this->provisioner->provision(self::ORGANIZATION_UUID, $body, $key);

        self::assertTrue($created->created);
        self::assertSame(self::ORGANIZATION_UUID, $created->data['organizationUuid']);
        self::assertSame('onboarding', $created->data['status']);
        self::assertSame('incomplete', $created->data['onboardingState']);
        self::assertSame((new CanonicalPayloadHasher())->prefixedHash($body), $created->data['payloadHash']);
        $supplierId = (int) $created->data['supplierId'];
        self::assertGreaterThan(0, $supplierId);

        $supplier = $this->row('SELECT company_name, ic, dic, is_vat_payer, is_identified FROM supplier WHERE id = ?', [$supplierId]);
        self::assertSame('Example Revize s.r.o.', $supplier['company_name']);
        self::assertSame('00000019', $supplier['ic']);
        self::assertNull($supplier['dic']);
        self::assertSame(0, (int) $supplier['is_vat_payer'], 'vatStatus=null se nesmí odvozovat z DIČ.');
        self::assertSame(0, (int) $supplier['is_identified']);

        $owner = $this->row(
            'SELECT u.role AS platform_role, u.is_active, us.role AS supplier_role, rul.active, rul.session_version
               FROM revizior_organization_links rol
               JOIN revizior_user_links rul ON rul.organization_link_id = rol.id
               JOIN users u ON u.id = rul.user_id
               JOIN user_suppliers us ON us.user_id = u.id AND us.supplier_id = rol.supplier_id
              WHERE rol.organization_uuid = ?',
            [self::ORGANIZATION_UUID],
        );
        self::assertSame('readonly', $owner['platform_role']);
        self::assertSame('supplier_owner', $owner['supplier_role']);
        self::assertSame(1, (int) $owner['is_active']);
        self::assertSame(1, (int) $owner['active']);
        self::assertSame(1, (int) $owner['session_version']);

        $retry = $this->provisioner->provision(self::ORGANIZATION_UUID, $body, $key);
        self::assertFalse($retry->created);
        self::assertSame($created->data, $retry->data);

        $secondKey = $this->provisioner->provision(self::ORGANIZATION_UUID, $body, $key . ':retry');
        self::assertFalse($secondKey->created);
        self::assertSame($created->data, $secondKey->data);

        self::assertSame(1, $this->rowCount('supplier', 'id = ?', [$supplierId]));
        self::assertSame(1, $this->rowCount('revizior_organization_links', 'organization_uuid = ?', [self::ORGANIZATION_UUID]));
        self::assertSame(1, $this->rowCount('revizior_user_links', 'user_uuid = ?', [self::OWNER_UUID]));
        self::assertSame(1, $this->rowCount('activity_log', 'supplier_id = ? AND action = ?', [$supplierId, 'revizior.organization.provisioned']));
        $audit = $this->row(
            'SELECT user_id FROM activity_log WHERE supplier_id = ? AND action = ?',
            [$supplierId, 'revizior.organization.provisioned'],
        );
        self::assertNull($audit['user_id'], 'Service provisioning se nesmí auditně vydávat za akci ownera.');
        self::assertSame(2, $this->rowCount('revizior_idempotency_keys', 'subject_uuid = ?', [self::ORGANIZATION_UUID]));
    }

    public function testSameIdempotencyKeyWithDifferentPayloadConflictsWithoutPartialWrite(): void
    {
        $body = $this->body();
        $key = 'provision:' . self::ORGANIZATION_UUID . ':v1';
        $first = $this->provisioner->provision(self::ORGANIZATION_UUID, $body, $key);
        $body['organization']['name'] = 'Jiná společnost s.r.o.';

        try {
            $this->provisioner->provision(self::ORGANIZATION_UUID, $body, $key);
            self::fail('Stejný idempotency key s jiným payloadem měl skončit konfliktem.');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('idempotency_conflict', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        $supplierId = (int) $first->data['supplierId'];
        $supplier = $this->row('SELECT company_name FROM supplier WHERE id = ?', [$supplierId]);
        self::assertSame('Example Revize s.r.o.', $supplier['company_name']);
        self::assertSame(1, $this->rowCount('supplier', 'id = ?', [$supplierId]));
        self::assertSame(1, $this->rowCount('revizior_idempotency_keys', 'subject_uuid = ?', [self::ORGANIZATION_UUID]));
    }

    public function testOrganizationUpdateChangesOnlyLiveSupplierAndIgnoresStaleSnapshot(): void
    {
        $this->provisioner->provision(
            self::ORGANIZATION_UUID,
            $this->body(),
            'provision:' . self::ORGANIZATION_UUID . ':v1',
        );
        $update = $this->organizationUpdateBody();
        $update['organization']['name'] = 'Aktualizovaná testovací firma s.r.o.';
        $update['organization']['vatStatus'] = 'payer';

        $result = $this->organizationSynchronizer->synchronize(self::ORGANIZATION_UUID, $update);
        self::assertFalse($result->created);
        self::assertSame('onboarding', $result->data['status']);
        $supplierId = (int) $result->data['supplierId'];
        $supplier = $this->row(
            'SELECT company_name, street, is_vat_payer, is_identified FROM supplier WHERE id = ?',
            [$supplierId],
        );
        self::assertSame('Aktualizovaná testovací firma s.r.o.', $supplier['company_name']);
        self::assertSame('Testovací 2', $supplier['street']);
        self::assertSame(1, (int) $supplier['is_vat_payer']);
        self::assertSame(0, (int) $supplier['is_identified']);

        $stale = $update;
        $stale['organization']['name'] = 'Stará hodnota se nesmí vrátit';
        $stale['organization']['sourceUpdatedAt'] = '2026-08-30T08:00:00Z';
        $this->organizationSynchronizer->synchronize(self::ORGANIZATION_UUID, $stale);
        $supplier = $this->row('SELECT company_name FROM supplier WHERE id = ?', [$supplierId]);
        self::assertSame('Aktualizovaná testovací firma s.r.o.', $supplier['company_name']);
        self::assertSame(1, $this->rowCount(
            'activity_log',
            'supplier_id = ? AND action = ?',
            [$supplierId, 'revizior.organization.updated'],
        ));

        $suspended = $update;
        $suspended['organization']['active'] = false;
        $suspended['organization']['sourceUpdatedAt'] = '2026-08-31T11:00:00Z';
        $suspendedResult = $this->organizationSynchronizer->synchronize(self::ORGANIZATION_UUID, $suspended);
        self::assertSame('suspended', $suspendedResult->data['status']);
        $owner = $this->row(
            'SELECT rul.user_id, rul.session_version
               FROM revizior_user_links rul
               JOIN revizior_organization_links rol ON rol.id = rul.organization_link_id
              WHERE rol.organization_uuid = ? AND rul.user_uuid = ?',
            [self::ORGANIZATION_UUID, self::OWNER_UUID],
        );
        self::assertSame(0, $this->rowCount(
            'user_suppliers',
            'user_id = ? AND supplier_id = ?',
            [(int) $owner['user_id'], $supplierId],
        ));
        self::assertSame(2, (int) $owner['session_version']);

        try {
            $this->userProvisioner->upsert(self::ORGANIZATION_UUID, self::USER_UUID, $this->userBody());
            self::fail('Suspendovaná organizace nesmí přijmout aktivní membership.');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('organization_suspended', $e->errorCode);
        }

        $reactivated = $suspended;
        $reactivated['organization']['active'] = true;
        $reactivated['organization']['sourceUpdatedAt'] = '2026-08-31T12:00:00Z';
        $reactivatedResult = $this->organizationSynchronizer->synchronize(self::ORGANIZATION_UUID, $reactivated);
        self::assertSame('onboarding', $reactivatedResult->data['status']);
        self::assertSame(1, $this->rowCount(
            'user_suppliers',
            'user_id = ? AND supplier_id = ?',
            [(int) $owner['user_id'], $supplierId],
        ));
        $ownerAfterReactivation = $this->row(
            'SELECT session_version FROM revizior_user_links WHERE user_uuid = ?',
            [self::OWNER_UUID],
        );
        self::assertSame(3, (int) $ownerAfterReactivation['session_version']);
    }

    public function testUserUpsertRoleChangeStaleSnapshotRevokeAndReactivationAreIdempotent(): void
    {
        $provisioned = $this->provisioner->provision(
            self::ORGANIZATION_UUID,
            $this->body(),
            'provision:' . self::ORGANIZATION_UUID . ':v1',
        );
        $supplierId = (int) $provisioned->data['supplierId'];
        $body = $this->userBody();

        $created = $this->userProvisioner->upsert(self::ORGANIZATION_UUID, self::USER_UUID, $body);
        self::assertTrue($created->created);
        self::assertSame('accountant', $created->data['role']);
        self::assertTrue($created->data['active']);
        $userId = (int) $created->data['externalUserId'];
        $user = $this->row(
            'SELECT u.role AS platform_role, us.role AS supplier_role, rul.active, rul.session_version
               FROM users u
               JOIN revizior_user_links rul ON rul.user_id = u.id
               JOIN user_suppliers us ON us.user_id = u.id AND us.supplier_id = ?
              WHERE u.id = ?',
            [$supplierId, $userId],
        );
        self::assertSame('readonly', $user['platform_role']);
        self::assertSame('accountant', $user['supplier_role']);
        self::assertSame(1, (int) $user['active']);
        self::assertSame(1, (int) $user['session_version']);

        $retry = $this->userProvisioner->upsert(self::ORGANIZATION_UUID, self::USER_UUID, $body);
        self::assertFalse($retry->created);
        self::assertSame($created->data, $retry->data);
        self::assertSame(1, $this->rowCount(
            'activity_log',
            'supplier_id = ? AND action = ?',
            [$supplierId, 'revizior.user.upserted'],
        ));

        $body['role'] = 'readonly';
        $body['sourceUpdatedAt'] = '2026-08-31T10:00:00Z';
        $this->userProvisioner->upsert(self::ORGANIZATION_UUID, self::USER_UUID, $body);
        $link = $this->row(
            'SELECT supplier_role, active, session_version FROM revizior_user_links
              WHERE user_uuid = ?',
            [self::USER_UUID],
        );
        self::assertSame('readonly', $link['supplier_role']);
        self::assertSame(2, (int) $link['session_version']);

        $stale = $body;
        $stale['role'] = 'accountant';
        $stale['sourceUpdatedAt'] = '2026-08-31T09:00:00Z';
        $this->userProvisioner->upsert(self::ORGANIZATION_UUID, self::USER_UUID, $stale);
        $link = $this->row(
            'SELECT supplier_role, session_version FROM revizior_user_links WHERE user_uuid = ?',
            [self::USER_UUID],
        );
        self::assertSame('readonly', $link['supplier_role']);
        self::assertSame(2, (int) $link['session_version']);

        $this->userProvisioner->revoke(self::ORGANIZATION_UUID, self::USER_UUID);
        self::assertSame(0, $this->rowCount('user_suppliers', 'user_id = ? AND supplier_id = ?', [$userId, $supplierId]));
        $revoked = $this->row(
            'SELECT active, session_version, revoked_at FROM revizior_user_links WHERE user_uuid = ?',
            [self::USER_UUID],
        );
        self::assertSame(0, (int) $revoked['active']);
        self::assertSame(3, (int) $revoked['session_version']);
        self::assertNotNull($revoked['revoked_at']);

        $this->userProvisioner->revoke(self::ORGANIZATION_UUID, self::USER_UUID);
        $revokedAgain = $this->row(
            'SELECT session_version FROM revizior_user_links WHERE user_uuid = ?',
            [self::USER_UUID],
        );
        self::assertSame(3, (int) $revokedAgain['session_version']);

        $body['active'] = true;
        $body['sourceUpdatedAt'] = '2026-08-31T11:00:00Z';
        $reactivated = $this->userProvisioner->upsert(self::ORGANIZATION_UUID, self::USER_UUID, $body);
        self::assertFalse($reactivated->created);
        self::assertTrue($reactivated->data['active']);
        self::assertSame(1, $this->rowCount('user_suppliers', 'user_id = ? AND supplier_id = ?', [$userId, $supplierId]));
        $active = $this->row(
            'SELECT active, session_version, revoked_at FROM revizior_user_links WHERE user_uuid = ?',
            [self::USER_UUID],
        );
        self::assertSame(1, (int) $active['active']);
        self::assertSame(4, (int) $active['session_version']);
        self::assertNull($active['revoked_at']);
    }

    public function testUserEmailCollisionWithAnotherExternalIdentityIsRejected(): void
    {
        $this->provisioner->provision(
            self::ORGANIZATION_UUID,
            $this->body(),
            'provision:' . self::ORGANIZATION_UUID . ':v1',
        );
        $body = $this->userBody();
        $body['email'] = self::OWNER_EMAIL;

        try {
            $this->userProvisioner->upsert(self::ORGANIZATION_UUID, self::USER_UUID, $body);
            self::fail('Kolize s jinou external identitou měla být odmítnuta.');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('user_link_conflict', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame(0, $this->rowCount('revizior_user_links', 'user_uuid = ?', [self::USER_UUID]));
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $body = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/source/revizior-integration/contract/v1/provision-request.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $body['owner']['userUuid'] = self::OWNER_UUID;
        $body['owner']['email'] = self::OWNER_EMAIL;
        return $body;
    }

    /** @return array<string,mixed> */
    private function organizationUpdateBody(): array
    {
        return json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4) . '/source/revizior-integration/contract/v1/organization-update.json',
            ),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,mixed> */
    private function userBody(): array
    {
        $body = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/source/revizior-integration/contract/v1/user-upsert.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $body['userUuid'] = self::USER_UUID;
        $body['email'] = self::USER_EMAIL;
        return $body;
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @param list<mixed> $params */
    private function rowCount(string $table, string $where, array $params): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT supplier_id FROM revizior_organization_links WHERE organization_uuid = ?');
        $stmt->execute([self::ORGANIZATION_UUID]);
        $supplierId = (int) ($stmt->fetchColumn() ?: 0);
        $userIds = [];
        if ($supplierId > 0) {
            $users = $pdo->prepare(
                'SELECT rul.user_id FROM revizior_user_links rul
                  JOIN revizior_organization_links rol ON rol.id = rul.organization_link_id
                 WHERE rol.organization_uuid = ?'
            );
            $users->execute([self::ORGANIZATION_UUID]);
            $userIds = array_map('intval', $users->fetchAll(\PDO::FETCH_COLUMN));
        }

        $pdo->prepare('DELETE FROM revizior_idempotency_keys WHERE subject_uuid = ?')->execute([self::ORGANIZATION_UUID]);
        if ($supplierId > 0) {
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplierId]);
            $pdo->prepare(
                'DELETE rul FROM revizior_user_links rul
                  JOIN revizior_organization_links rol ON rol.id = rul.organization_link_id
                 WHERE rol.organization_uuid = ?'
            )->execute([self::ORGANIZATION_UUID]);
            $pdo->prepare('DELETE FROM user_suppliers WHERE supplier_id = ?')->execute([$supplierId]);
            $pdo->prepare('DELETE FROM revizior_organization_links WHERE organization_uuid = ?')->execute([self::ORGANIZATION_UUID]);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$supplierId]);
                $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')->execute([self::OWNER_EMAIL, self::USER_EMAIL]);
    }
}
