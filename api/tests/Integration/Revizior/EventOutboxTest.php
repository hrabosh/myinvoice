<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftService;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceEventPublisher;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorOutboxDispatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Outbox nad skutečnou DB: událost vzniká s business změnou, sekvence roste
 * monotónně, rollback nenechá nic a doklad bez vazby negeneruje nic.
 */
#[Group('integration')]
final class EventOutboxTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000005';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000041';
    private const OWNER_EMAIL = 'revizior-outbox-owner@example.invalid';
    private const CLIENT_UUID = '40000000-0000-4000-8000-000000000001';
    private const INVOICE_KEY = '60000000-0000-4000-8000-000000000001';

    private Connection $db;
    private ReviziorInvoiceEventPublisher $publisher;
    private ReviziorOutboxDispatcher $dispatcher;
    private int $supplierId;
    private int $invoiceId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->publisher = $container->get(ReviziorInvoiceEventPublisher::class);
            $this->dispatcher = $container->get(ReviziorOutboxDispatcher::class);
            $provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $clients = $container->get(ReviziorClientSynchronizer::class);
            $drafts = $container->get(ReviziorInvoiceDraftService::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->db->pdo()->query("SHOW TABLES LIKE 'revizior_event_outbox'")->fetchColumn() === false) {
            $this->markTestSkipped('Migrace 0157_revizior_event_outbox.sql chybí.');
        }
        $this->cleanup();
        $provisioned = $provisioner->provision(self::ORGANIZATION_UUID, $this->provisionBody(), 'provision:' . self::ORGANIZATION_UUID);
        $this->supplierId = (int) $provisioned->data['supplierId'];
        $clients->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $this->fixture('client-upsert-request'));
        $draft = $drafts->create(self::ORGANIZATION_UUID, $this->fixture('invoice-draft-request'), 'invoice-draft:' . self::INVOICE_KEY);
        $this->invoiceId = (int) $draft->data['invoiceId'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testDraftCreationAlreadyProducedAnEventAndSequenceGrowsMonotonically(): void
    {
        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame('invoice.draft_created', $events[0]['event_type']);
        self::assertSame(1, (int) $events[0]['aggregate_sequence']);

        $this->publisher->publish($this->invoiceId, ReviziorInvoiceEventPublisher::TYPE_ISSUED);
        $this->publisher->publish($this->invoiceId, ReviziorInvoiceEventPublisher::TYPE_SENT);

        $events = $this->events();
        self::assertSame(
            ['invoice.draft_created', 'invoice.issued', 'invoice.sent'],
            array_map(static fn (array $row): string => (string) $row['event_type'], $events),
        );
        self::assertSame([1, 2, 3], array_map(static fn (array $row): int => (int) $row['aggregate_sequence'], $events));

        $payload = json_decode((string) $events[1]['payload_json'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $payload['specVersion']);
        self::assertSame(self::ORGANIZATION_UUID, $payload['organizationId']);
        self::assertSame((string) $this->supplierId, $payload['supplierId']);
        self::assertSame(self::INVOICE_KEY, $payload['aggregate']['externalKey']);
        self::assertSame(2, $payload['aggregate']['sequence']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['eventId']);
        self::assertNotSame($payload['eventId'], json_decode((string) $events[0]['payload_json'], true)['eventId']);
    }

    /** Rollback business transakce musí zahodit i událost. */
    public function testRolledBackChangeLeavesNoEvent(): void
    {
        $before = count($this->events());
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->publisher->publish($this->invoiceId, ReviziorInvoiceEventPublisher::TYPE_ISSUED);
        $pdo->rollBack();

        self::assertCount($before, $this->events());
        $sequence = $this->db->pdo()->prepare('SELECT event_sequence FROM revizior_invoice_links WHERE invoice_id = ?');
        $sequence->execute([$this->invoiceId]);
        self::assertSame($before, (int) $sequence->fetchColumn(), 'Sekvence se nesmí posunout, když změna neplatí.');
    }

    /** Faktura bez ReviziOR vazby (běžná v UI) žádnou událost negeneruje. */
    public function testInvoiceWithoutLinkIsANoOp(): void
    {
        $before = count($this->events());
        $this->publisher->publish(999999, ReviziorInvoiceEventPublisher::TYPE_ISSUED);
        self::assertCount($before, $this->events());
    }

    public function testDispatcherClaimsRetriesAndDeadLetters(): void
    {
        // Bez konfigurace callbacku dispatcher nic nedělá — a hlavně nic nezahodí.
        $result = $this->dispatcher->dispatch();
        self::assertSame(['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0], $result);
        self::assertCount(1, $this->events());

        $status = $this->dispatcher->status();
        self::assertGreaterThanOrEqual(1, $status['pending']);
        self::assertIsInt($status['oldest_pending_age_seconds']);

        // Dead letter se dá vrátit do fronty; `pending` ne.
        $eventId = (string) $this->events()[0]['id'];
        self::assertFalse($this->dispatcher->requeue($eventId));
        $this->db->pdo()->prepare("UPDATE revizior_event_outbox SET state = 'failed' WHERE id = ?")->execute([$eventId]);
        self::assertTrue($this->dispatcher->requeue($eventId));
        self::assertSame('pending', $this->events()[0]['state']);
    }

    /** @return list<array<string,mixed>> */
    private function events(): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT o.id, o.event_type, o.aggregate_sequence, o.state, o.payload_json
               FROM revizior_event_outbox o
               JOIN revizior_organization_links rol ON rol.id = o.organization_link_id
              WHERE rol.organization_uuid = ?
              ORDER BY o.aggregate_sequence'
        );
        $statement->execute([self::ORGANIZATION_UUID]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
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
        $statement = $pdo->prepare('SELECT id, supplier_id FROM revizior_organization_links WHERE organization_uuid = ?');
        $statement->execute([self::ORGANIZATION_UUID]);
        $org = $statement->fetch(\PDO::FETCH_ASSOC);
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

        $pdo->prepare('DELETE FROM revizior_event_outbox WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplierId]);
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
