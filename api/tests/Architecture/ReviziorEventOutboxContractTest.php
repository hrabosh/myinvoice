<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviziorEventOutboxContractTest extends TestCase
{
    public function testOutboxRowIsWrittenInTheBusinessTransactionAndNeverRegenerated(): void
    {
        $publisher = $this->read('api/src/Service/Integration/Revizior/ReviziorInvoiceEventPublisher.php');
        self::assertStringContainsString('inTransaction()', $publisher);
        self::assertStringContainsString('event_sequence = ?', $publisher);
        self::assertStringContainsString('FOR UPDATE', $publisher);
        self::assertStringContainsString('revizior_event_outbox', $publisher);

        $dispatcher = $this->read('api/src/Service/Integration/Revizior/ReviziorOutboxDispatcher.php');
        // Dispatcher payload jen čte; kdyby ho stavěl, doručila by se po výpadku
        // aktuální podoba faktury místo té, která událost vyvolala.
        self::assertStringContainsString('payload_json', $dispatcher);
        self::assertStringNotContainsString('SnapshotBuilder', $dispatcher);
        self::assertStringNotContainsString('InvoiceRepository', $dispatcher);
        self::assertStringContainsString('claimed_by', $dispatcher);
        self::assertStringContainsString('Retry-After', str_replace('retry-after', 'Retry-After', $dispatcher));
    }

    /** @return iterable<string,array{string,string}> */
    public static function hookPoints(): iterable
    {
        yield 'draft created' => ['api/src/Service/Integration/Revizior/ReviziorInvoiceDraftService.php', 'TYPE_DRAFT_CREATED'];
        yield 'issued' => ['api/src/Action/Invoice/IssueInvoiceAction.php', 'TYPE_ISSUED'];
        yield 'sent' => ['api/src/Action/Invoice/SendEmailAction.php', 'TYPE_SENT'];
        yield 'marked paid' => ['api/src/Action/Invoice/MarkPaidAction.php', 'TYPE_PAID'];
        yield 'cancelled' => ['api/src/Action/Invoice/CancelInvoiceAction.php', 'TYPE_CANCELLED'];
        yield 'credit note' => ['api/src/Action/Invoice/CancelInvoiceAction.php', 'TYPE_CREDIT_NOTE_ISSUED'];
        yield 'draft deleted' => ['api/src/Action/Invoice/DeleteInvoiceAction.php', 'TYPE_DELETED_DRAFT'];
        yield 'payment' => ['api/src/Service/Invoice/InvoicePaymentService.php', 'publishPayment('];
    }

    #[DataProvider('hookPoints')]
    public function testEveryLifecycleChannelPublishes(string $file, string $marker): void
    {
        self::assertStringContainsString($marker, $this->read($file));
    }

    public function testMigrationKeepsSequenceUniquePerInvoiceLink(): void
    {
        $migration = $this->read('db/migrations/0157_revizior_event_outbox.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS revizior_event_outbox', $migration);
        self::assertStringContainsString('uq_revizior_outbox_invoice_sequence (invoice_link_id, aggregate_sequence)', $migration);
        self::assertStringContainsString('idx_revizior_outbox_pending (state, next_attempt_at)', $migration);
        self::assertStringNotContainsString('DROP ', strtoupper($migration));
    }

    public function testOperatorHasCliAndCronWrappers(): void
    {
        $cli = $this->read('api/bin/cron-revizior-outbox.php');
        self::assertStringContainsString('--status', $cli);
        self::assertStringContainsString('--retry', $cli);
        self::assertStringContainsString('isReviziorManaged()', $cli);
        foreach (['cmd/cron-revizior-outbox.sh', 'cmd/cron-revizior-outbox.cmd'] as $wrapper) {
            self::assertStringContainsString('cron-revizior-outbox.php', $this->read($wrapper));
        }
        foreach (['docker-compose.yml', 'docker-compose.production.yml'] as $compose) {
            self::assertStringContainsString('MYINVOICE_REVIZIOR_CALLBACK_URL', $this->read($compose));
        }
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($content);
        return $content;
    }
}
