<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorAttachmentContractTest extends TestCase
{
    private const PATH = '/api/integrations/revizior/v1/organizations/{organizationUuid}/invoice-drafts/{externalInvoiceKey}/attachments/{externalAttachmentKey}';

    public function testRouteUsesDedicatedScopeAndBothOpenApiDocumentsDescribeIt(): void
    {
        self::assertStringContainsString("'" . self::PATH . "'", $this->read('api/src/Routes.php'));
        $middleware = $this->read('api/src/Middleware/ReviziorServiceAuthMiddleware.php');
        self::assertStringContainsString("'attachment:write'", $middleware);
        // `invoice:write` na přílohu stačit nemá — je to zápis na disk.
        self::assertStringContainsString('/attachments/', $middleware);
        foreach (['api/openapi.yaml', 'api/openapi-revizior-integration.yaml'] as $document) {
            self::assertStringContainsString(self::PATH, $this->read($document));
        }
    }

    public function testUploadStreamsValidatesAndNeverTrustsTheClientFileName(): void
    {
        $service = $this->read('api/src/Service/Integration/Revizior/ReviziorAttachmentService.php');

        self::assertStringContainsString('hash_init(', $service);
        self::assertStringContainsString('tempnam(', $service);
        self::assertStringContainsString('%PDF-', $service);
        self::assertStringContainsString('finfo', $service);
        self::assertStringContainsString('hash_equals(', $service);
        self::assertStringContainsString('MAX_BYTES', $service);
        // Jméno v úložišti generuje služba, klientské jde jen do zobrazení.
        self::assertStringContainsString("'revizior-' . \$attachmentKey . '.pdf'", $service);
        self::assertStringContainsString('basename(', $service);
        // Celé tělo se nikdy nedrží v paměti.
        self::assertStringNotContainsString('getContents()', $service);
        self::assertStringNotContainsString('file_get_contents($', $service);
    }

    public function testActionNeverLogsAttachmentContent(): void
    {
        $action = $this->read('api/src/Action/Revizior/PutReviziorInvoiceAttachmentAction.php');
        self::assertStringContainsString("'exception' => \$e::class", $action);
        self::assertStringNotContainsString('getBody()->getContents()', $action);
        self::assertStringNotContainsString("'body'", $action);
    }

    public function testMigrationKeepsExternalKeyAndAttachmentUnique(): void
    {
        $migration = $this->read('db/migrations/0158_revizior_attachment_links.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS revizior_attachment_links', $migration);
        self::assertStringContainsString('uq_revizior_attachment_external (invoice_link_id, external_attachment_key)', $migration);
        self::assertStringContainsString('uq_revizior_attachment_internal (attachment_id)', $migration);
        self::assertStringContainsString('REFERENCES invoice_attachments(id)', $migration);
        self::assertStringNotContainsString('DROP ', strtoupper($migration));
    }

    /** Odkaz na revizi se skládá z konfigurace, ne ze vstupu requestu. */
    public function testInvoiceSourceReadModelIsExposedAndManagedOnly(): void
    {
        self::assertStringContainsString(
            "'/api/invoices/{id:[0-9]+}/revizior-sources'",
            $this->read('api/src/Routes.php'),
        );
        $action = $this->read('api/src/Action/Invoice/ReviziorSourcesAction.php');
        self::assertStringContainsString('isReviziorManaged()', $action);
        self::assertStringContainsString('SupplierGuard::owns(', $action);

        $reader = $this->read('api/src/Service/Integration/Revizior/ReviziorInvoiceSourceReader.php');
        self::assertStringContainsString('deployment.revizior.app_url', $reader);
        self::assertStringContainsString('i.supplier_id = ?', $reader);
        self::assertStringNotContainsString('getQueryParams', $reader);
        self::assertStringNotContainsString('getHeaderLine', $reader);
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($content);

        return $content;
    }
}
