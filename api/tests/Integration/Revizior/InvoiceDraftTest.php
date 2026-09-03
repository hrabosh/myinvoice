<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftService;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Idempotentní koncept: jeden externalInvoiceKey = nejvýš jeden doklad,
 * částky počítá MyInvoice, vazba a doklad vznikají spolu nebo vůbec.
 */
#[Group('integration')]
final class InvoiceDraftTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000003';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000021';
    private const OWNER_EMAIL = 'revizior-draft-owner@example.invalid';
    private const CLIENT_UUID = '40000000-0000-4000-8000-000000000001';
    private const INVOICE_KEY = '60000000-0000-4000-8000-000000000001';

    private Connection $db;
    private ReviziorInvoiceDraftService $service;
    private int $supplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(ReviziorInvoiceDraftService::class);
            $provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $clients = $container->get(ReviziorClientSynchronizer::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        foreach (['revizior_client_links', 'revizior_invoice_links', 'revizior_invoice_sources'] as $table) {
            if ($this->db->pdo()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn() === false) {
                $this->markTestSkipped("Tabulka {$table} chybí — spusť api/bin/migrate.php.");
            }
        }
        $this->cleanup();
        $provisioned = $provisioner->provision(self::ORGANIZATION_UUID, $this->provisionBody(), 'provision:' . self::ORGANIZATION_UUID);
        $this->supplierId = (int) $provisioned->data['supplierId'];
        $clients->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $this->fixture('client-upsert-request'));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testDraftIsCreatedOnceComputedByMyInvoiceAndReplayedIdempotently(): void
    {
        $body = $this->fixture('invoice-draft-request');
        $key = 'invoice-draft:' . self::INVOICE_KEY;

        $created = $this->service->create(self::ORGANIZATION_UUID, $body, $key);
        self::assertTrue($created->created);
        $data = $created->data;
        self::assertSame(self::INVOICE_KEY, $data['externalInvoiceKey']);
        self::assertSame('draft', $data['status']);
        self::assertNull($data['invoiceNumber']);
        self::assertSame('CZK', $data['currency']);
        self::assertSame(544500, $data['totalMinor'], '4 500 + 21 % DPH počítá MyInvoice, ne integrace.');
        self::assertSame(544500, $data['amountDueMinor']);
        self::assertSame(['2026-08-30', '2026-09-13'], [$data['issueDate'], $data['dueDate']]);
        self::assertSame('/invoices/' . $data['invoiceId'] . '/edit', $data['editPath']);
        // R5: založení konceptu je zároveň událost `invoice.draft_created`,
        // takže sekvence je 1 — snapshot ji hlásí stejně jako outbox.
        self::assertSame(1, $data['sequence']);
        $invoiceId = (int) $data['invoiceId'];

        $invoice = $this->row('SELECT supplier_id, client_id, status, language, prices_include_vat, total_without_vat, total_vat, total_with_vat FROM invoices WHERE id = ?', [$invoiceId]);
        self::assertSame($this->supplierId, (int) $invoice['supplier_id']);
        self::assertSame('draft', $invoice['status']);
        self::assertSame(4500.0, (float) $invoice['total_without_vat']);
        self::assertSame(945.0, (float) $invoice['total_vat']);
        $item = $this->row('SELECT description, quantity, unit, unit_price_without_vat, vat_rate_snapshot FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);
        self::assertSame('Pravidelná revize elektroinstalace', $item['description']);
        self::assertSame(21.0, (float) $item['vat_rate_snapshot']);
        $source = $this->row(
            'SELECT s.source_type, s.source_uuid, s.external_line_key, l.event_sequence
               FROM revizior_invoice_sources s JOIN revizior_invoice_links l ON l.id = s.invoice_link_id
              WHERE l.external_invoice_key = ?',
            [self::INVOICE_KEY],
        );
        self::assertSame('revision_report', $source['source_type']);
        self::assertSame('70000000-0000-4000-8000-000000000001', $source['source_uuid']);
        self::assertSame(1, (int) $source['event_sequence']);

        $event = $this->row(
            "SELECT event_type, aggregate_sequence, state, delivery_attempts, payload_json
               FROM revizior_event_outbox WHERE aggregate_id = ?",
            [(string) $invoiceId],
        );
        self::assertSame('invoice.draft_created', $event['event_type']);
        self::assertSame(1, (int) $event['aggregate_sequence']);
        self::assertSame('pending', $event['state']);
        self::assertSame(0, (int) $event['delivery_attempts']);
        $payload = json_decode((string) $event['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(self::INVOICE_KEY, $payload['aggregate']['externalKey']);
        self::assertSame('draft', $payload['data']['status']);
        self::assertSame(544500, $payload['data']['totalMinor']);
        self::assertNull($payload['data']['issuedAt'], 'Koncept ještě vystavený není.');

        $replay = $this->service->create(self::ORGANIZATION_UUID, $body, $key);
        self::assertFalse($replay->created);
        self::assertSame($data, $replay->data);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?', [$this->supplierId]));

        $otherKey = $this->service->create(self::ORGANIZATION_UUID, $body, 'invoice-draft:retry-' . self::INVOICE_KEY);
        self::assertFalse($otherKey->created, 'Jiný Idempotency-Key se stejným payloadem je bezpečné opakování.');
        self::assertSame($data['invoiceId'], $otherKey->data['invoiceId']);

        self::assertSame($data, $this->service->snapshot(self::ORGANIZATION_UUID, self::INVOICE_KEY));
    }

    public function testConflictsAndMissingLinksAreRejectedWithoutLeavingAnInvoice(): void
    {
        $body = $this->fixture('invoice-draft-request');
        $key = 'invoice-draft:' . self::INVOICE_KEY;
        $this->service->create(self::ORGANIZATION_UUID, $body, $key);

        $changed = $body;
        $changed['items'][0]['unitPrice'] = '9999.00';
        $this->assertRejected($changed, $key, 'idempotency_conflict', 409);
        $this->assertRejected($changed, 'invoice-draft:other', 'invoice_link_conflict', 409);

        $unknownClient = $body;
        $unknownClient['externalInvoiceKey'] = '60000000-0000-4000-8000-000000000009';
        $unknownClient['clientUuid'] = '40000000-0000-4000-8000-000000000009';
        $this->assertRejected($unknownClient, 'invoice-draft:' . $unknownClient['externalInvoiceKey'], 'client_not_linked', 404);

        $unknownVat = $body;
        $unknownVat['externalInvoiceKey'] = '60000000-0000-4000-8000-000000000008';
        $unknownVat['items'][0]['vatRate'] = '19.00';
        try {
            $this->service->create(self::ORGANIZATION_UUID, $unknownVat, 'invoice-draft:' . $unknownVat['externalInvoiceKey']);
            self::fail('unknown VAT rate must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('invoice_validation_failed', $e->errorCode);
            self::assertSame(['items.0.vatRate' => 'unknown_vat_rate'], $e->fields);
        }

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM invoices WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM revizior_invoice_links l JOIN revizior_organization_links o ON o.id = l.organization_link_id WHERE o.organization_uuid = ?', [self::ORGANIZATION_UUID]));

        try {
            $this->service->snapshot(self::ORGANIZATION_UUID, '60000000-0000-4000-8000-000000000009');
            self::fail('unknown key must be 404');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('invoice_not_found', $e->errorCode);
        }
    }

    /** @param array<string,mixed> $body */
    private function assertRejected(array $body, string $key, string $code, int $status): void
    {
        try {
            $this->service->create(self::ORGANIZATION_UUID, $body, $key);
            self::fail("expected {$code}");
        } catch (ReviziorProvisioningException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->httpStatus);
        }
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
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
    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . "/source/revizior-integration/contract/v1/{$name}.json"),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,mixed> */
    private function provisionBody(): array
    {
        $body = $this->fixture('provision-request');
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
        $pdo->prepare('DELETE FROM revizior_event_outbox WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE s FROM revizior_invoice_sources s JOIN revizior_invoice_links l ON l.id = s.invoice_link_id WHERE l.organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM revizior_invoice_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$supplierId]);
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
