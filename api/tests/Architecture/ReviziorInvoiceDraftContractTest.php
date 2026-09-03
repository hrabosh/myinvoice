<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorInvoiceDraftContractTest extends TestCase
{
    private const DRAFT_PATH = '/api/integrations/revizior/v1/organizations/{organizationUuid}/invoice-drafts';
    private const READ_PATH = '/api/integrations/revizior/v1/organizations/{organizationUuid}/invoices/{externalInvoiceKey}';

    public function testRoutesUseDedicatedScopesAndBothOpenApiDocumentsDescribeThem(): void
    {
        $routes = $this->read('api/src/Routes.php');
        self::assertStringContainsString("'" . self::DRAFT_PATH . "'", $routes);
        self::assertStringContainsString("'" . self::READ_PATH . "'", $routes);
        $middleware = $this->read('api/src/Middleware/ReviziorServiceAuthMiddleware.php');
        self::assertStringContainsString("'invoice:write'", $middleware);
        self::assertStringContainsString("'invoice:read'", $middleware);
        foreach (['api/openapi.yaml', 'api/openapi-revizior-integration.yaml'] as $doc) {
            self::assertStringContainsString(self::DRAFT_PATH, $this->read($doc));
            self::assertStringContainsString(self::READ_PATH, $this->read($doc));
        }
    }

    /** Integrace nemá vlastní invoice math ani zápis dokladu — jde přes InvoiceDraftCreator. */
    public function testDraftServiceUsesSharedCreatorInsideOneIdempotentTransaction(): void
    {
        $service = $this->read('api/src/Service/Integration/Revizior/ReviziorInvoiceDraftService.php');
        self::assertStringContainsString('beginTransaction()', $service);
        self::assertStringContainsString('InvoiceDraftCreator', $service);
        self::assertStringContainsString('revizior_idempotency_keys', $service);
        self::assertStringContainsString('revizior_invoice_links', $service);
        self::assertStringContainsString('revizior_invoice_sources', $service);
        self::assertStringContainsString('idempotency_conflict', $service);
        self::assertStringNotContainsString('INSERT INTO invoices', $service);
        self::assertStringNotContainsString('INSERT INTO invoice_items', $service);
        self::assertStringNotContainsString('InvoiceMath', $service);
        self::assertStringNotContainsString('total_with_vat', $service);
    }

    public function testMigrationIsAdditiveWithUniqueInvoiceAndExternalKey(): void
    {
        $migration = $this->read('db/migrations/0155_revizior_invoice_links.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS revizior_invoice_links', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS revizior_invoice_sources', $migration);
        self::assertStringContainsString('uq_revizior_invoice_external (organization_link_id, external_invoice_key)', $migration);
        self::assertStringContainsString('uq_revizior_invoice_internal (invoice_id)', $migration);
        self::assertStringContainsString('event_sequence', $migration);
        self::assertStringNotContainsString('DROP ', strtoupper($migration));
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($content);
        return $content;
    }
}
