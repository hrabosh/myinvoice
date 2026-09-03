<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\Revizior\CanonicalPayloadHasher;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Upsert klienta přes sdílený ClientWriter: identita = UUID, idempotence
 * = kanonický hash, hodnoty z UI se resyncem nepřepisují.
 */
#[Group('integration')]
final class ClientSynchronizationTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000002';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000011';
    private const OWNER_EMAIL = 'revizior-client-owner@example.invalid';
    private const CLIENT_UUID = '49000000-0000-4000-8000-000000000001';

    private Connection $db;
    private ReviziorClientSynchronizer $synchronizer;
    private int $supplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->synchronizer = $container->get(ReviziorClientSynchronizer::class);
            $provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->db->pdo()->query("SHOW TABLES LIKE 'revizior_client_links'")->fetchColumn() === false) {
            $this->markTestSkipped('Migrace 0154_revizior_client_links.sql chybí.');
        }
        $this->cleanup();
        $provisioned = $provisioner->provision(self::ORGANIZATION_UUID, $this->provisionBody(), 'provision:' . self::ORGANIZATION_UUID);
        $this->supplierId = (int) $provisioned->data['supplierId'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testCreateRetryAndUpdatePreserveUiOwnedFields(): void
    {
        $body = $this->fixture();
        $created = $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);

        self::assertSame('created', $created->data['operation']);
        self::assertSame(self::CLIENT_UUID, $created->data['clientUuid']);
        self::assertSame((new CanonicalPayloadHasher())->prefixedHash($body), $created->data['payloadHash']);
        $clientId = (int) $created->data['externalClientId'];
        self::assertGreaterThan(0, $clientId);

        $client = $this->row(
            'SELECT c.supplier_id, c.company_name, c.ic, c.dic, c.street, c.city, c.zip, c.language, c.main_email, c.archived_at, co.iso2
               FROM clients c JOIN countries co ON co.id = c.country_id WHERE c.id = ?',
            [$clientId],
        );
        self::assertSame($this->supplierId, (int) $client['supplier_id']);
        self::assertSame('Example Client s.r.o.', $client['company_name']);
        self::assertSame('00000019', $client['ic']);
        self::assertNull($client['dic']);
        self::assertSame(['Zkušební 10', 'Praha', '11000', 'CZ'], [$client['street'], $client['city'], $client['zip'], $client['iso2']]);
        self::assertSame('billing@example.invalid', $client['main_email']);
        self::assertNull($client['archived_at']);

        $contact = $this->row('SELECT email, contact_name, usages FROM client_email_contacts WHERE client_id = ?', [$clientId]);
        self::assertSame('billing@example.invalid', $contact['email']);
        self::assertSame('Testovací kontakt', $contact['contact_name']);
        self::assertStringContainsString('documents', (string) $contact['usages']);

        $link = $this->row('SELECT client_id, payload_hash FROM revizior_client_links WHERE client_uuid = ?', [self::CLIENT_UUID]);
        self::assertSame($clientId, (int) $link['client_id']);
        self::assertSame(1, $this->auditCount());

        $retry = $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);
        self::assertSame('unchanged', $retry->data['operation']);
        self::assertSame($created->data['payloadHash'], $retry->data['payloadHash']);
        self::assertSame(1, $this->auditCount(), 'Stejný payload nesmí přidávat audit záznamy.');

        // Uživatel si ve fakturaci doplnil, co ReviziOR nezná.
        $this->db->pdo()->prepare('UPDATE clients SET note = ?, hourly_rate = 500, payment_due_default = 30, city = ? WHERE id = ?')
            ->execute(['poznámka z UI', 'Brno', $clientId]);

        $changed = $body;
        $changed['companyName'] = 'Example Client a.s.';
        $changed['address']['city'] = null;
        $changed['address']['postalCode'] = null;
        $changed['address']['countryCode'] = null;
        $changed['sourceUpdatedAt'] = '2026-09-01T10:00:00Z';
        $updated = $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $changed);
        self::assertSame('updated', $updated->data['operation']);
        self::assertSame((string) $clientId, $updated->data['externalClientId']);

        $after = $this->row('SELECT company_name, city, zip, note, hourly_rate, payment_due_default FROM clients WHERE id = ?', [$clientId]);
        self::assertSame('Example Client a.s.', $after['company_name']);
        self::assertSame('Brno', $after['city'], 'null z ReviziORu nesmí přepsat město doplněné ve fakturaci.');
        self::assertSame('11000', $after['zip']);
        self::assertSame('poznámka z UI', $after['note']);
        self::assertSame(500.0, (float) $after['hourly_rate']);
        self::assertSame(30, (int) $after['payment_due_default']);
        self::assertSame(2, $this->auditCount());
    }

    public function testStaleSourceIsIgnoredAndActiveFlagDrivesArchiving(): void
    {
        $body = $this->fixture();
        $created = $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);
        $clientId = (int) $created->data['externalClientId'];

        $stale = $body;
        $stale['companyName'] = 'Starší jméno';
        $stale['sourceUpdatedAt'] = '2026-08-30T10:00:00Z';
        $result = $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $stale);
        self::assertSame('unchanged', $result->data['operation']);
        self::assertSame('Example Client s.r.o.', $this->row('SELECT company_name FROM clients WHERE id = ?', [$clientId])['company_name']);

        $inactive = $body;
        $inactive['active'] = false;
        $inactive['sourceUpdatedAt'] = '2026-09-01T10:00:00Z';
        self::assertSame('updated', $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $inactive)->data['operation']);
        self::assertNotNull($this->row('SELECT archived_at FROM clients WHERE id = ?', [$clientId])['archived_at']);

        $active = $body;
        $active['sourceUpdatedAt'] = '2026-09-02T10:00:00Z';
        $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $active);
        self::assertNull($this->row('SELECT archived_at FROM clients WHERE id = ?', [$clientId])['archived_at']);
    }

    public function testNewClientWithoutAddressPartsUsesSupplierCountryAndStaysIncomplete(): void
    {
        $body = $this->fixture();
        $body['address'] = ['street' => 'Nádražní 12, Brno', 'city' => null, 'postalCode' => null, 'countryCode' => null];
        $created = $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);

        $client = $this->row(
            'SELECT c.street, c.city, c.zip, co.iso2 FROM clients c JOIN countries co ON co.id = c.country_id WHERE c.id = ?',
            [(int) $created->data['externalClientId']],
        );
        self::assertSame('Nádražní 12, Brno', $client['street']);
        self::assertSame('', $client['city']);
        self::assertSame('', $client['zip']);
        self::assertSame('CZ', $client['iso2'], 'Země dodavatele je stejný default jako ve formuláři.');
    }

    public function testUnknownCountryForeignSupplierAndSuspendedOrganizationAreRejected(): void
    {
        $body = $this->fixture();
        $body['address']['countryCode'] = 'XX';
        try {
            $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);
            self::fail('unknown country must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('client_validation_failed', $e->errorCode);
            self::assertSame(['address.countryCode' => 'unknown_country'], $e->fields);
        }
        $link = $this->db->pdo()->prepare(
            'SELECT 1 FROM revizior_client_links rcl
               JOIN revizior_organization_links rol ON rol.id = rcl.organization_link_id
              WHERE rol.organization_uuid = ?'
        );
        $link->execute([self::ORGANIZATION_UUID]);
        self::assertFalse($link->fetchColumn(), 'Odmítnutý upsert nesmí nechat link.');

        $body['address']['countryCode'] = 'CZ';
        $created = $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);
        $clientId = (int) $created->data['externalClientId'];

        // Klient „přeběhl“ k jinému dodavateli (nemělo by se stát) — integrace se ho nedotkne.
        $this->db->pdo()->prepare('UPDATE clients SET supplier_id = 2 WHERE id = ?')->execute([$clientId]);
        $changed = $body;
        $changed['companyName'] = 'Cizí';
        $changed['sourceUpdatedAt'] = '2026-09-01T10:00:00Z';
        try {
            $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $changed);
            self::fail('foreign supplier must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('client_link_conflict', $e->errorCode);
        }
        $this->db->pdo()->prepare('UPDATE clients SET supplier_id = ? WHERE id = ?')->execute([$this->supplierId, $clientId]);

        $this->db->pdo()->prepare("UPDATE revizior_organization_links SET status = 'suspended' WHERE organization_uuid = ?")
            ->execute([self::ORGANIZATION_UUID]);
        try {
            $this->synchronizer->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $changed);
            self::fail('suspended organization must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('organization_suspended', $e->errorCode);
        }
    }

    private function auditCount(): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'revizior.client.upserted'");
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Řádek nenalezen: ' . $sql);
        return $row;
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/source/revizior-integration/contract/v1/client-upsert-request.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,mixed> */
    private function provisionBody(): array
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

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, supplier_id FROM revizior_organization_links WHERE organization_uuid = ?');
        $stmt->execute([self::ORGANIZATION_UUID]);
        $org = $stmt->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare('DELETE FROM revizior_idempotency_keys WHERE subject_uuid = ?')->execute([self::ORGANIZATION_UUID]);
        if (!is_array($org)) {
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::OWNER_EMAIL]);
            return;
        }
        $orgLinkId = (int) $org['id'];
        $supplierId = (int) $org['supplier_id'];
        $users = $pdo->prepare('SELECT user_id FROM revizior_user_links WHERE organization_link_id = ?');
        $users->execute([$orgLinkId]);
        $userIds = array_map('intval', $users->fetchAll(\PDO::FETCH_COLUMN));

        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_client_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE cec FROM client_email_contacts cec JOIN clients c ON c.id = cec.client_id WHERE c.supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_user_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM user_suppliers WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_organization_links WHERE id = ?')->execute([$orgLinkId]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::OWNER_EMAIL]);
    }
}
