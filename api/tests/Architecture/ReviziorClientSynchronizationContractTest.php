<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorClientSynchronizationContractTest extends TestCase
{
    private const PATH = '/api/integrations/revizior/v1/organizations/{organizationUuid}/clients/{clientUuid}';

    public function testClientRouteUsesDedicatedScopeAndBothOpenApiDocumentsDescribeIt(): void
    {
        self::assertStringContainsString("'" . self::PATH . "'", $this->read('api/src/Routes.php'));
        $middleware = $this->read('api/src/Middleware/ReviziorServiceAuthMiddleware.php');
        self::assertStringContainsString("'client:write'", $middleware);
        self::assertStringContainsString('/clients/{clientUuid}', $middleware);
        self::assertStringContainsString(self::PATH, $this->read('api/openapi.yaml'));
        self::assertStringContainsString(self::PATH, $this->read('api/openapi-revizior-integration.yaml'));
    }

    /** Integrace nesmí mít vlastní zápis klienta — jde přes sdílený ClientWriter. */
    public function testSynchronizerWritesThroughSharedClientWriterInsideOneTransaction(): void
    {
        $service = $this->read('api/src/Service/Integration/Revizior/ReviziorClientSynchronizer.php');

        self::assertStringContainsString('beginTransaction()', $service);
        self::assertStringContainsString('ClientWriter', $service);
        self::assertStringContainsString('revizior_client_links', $service);
        self::assertStringContainsString('revizior.client.upserted', $service);
        self::assertStringNotContainsString('INSERT INTO clients', $service);
        self::assertStringNotContainsString('UPDATE clients SET', $service);
        self::assertStringNotContainsString('WHERE ic =', $service);
    }

    public function testMigrationIsAdditiveAndTenantScoped(): void
    {
        $migration = $this->read('db/migrations/0154_revizior_client_links.sql');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS revizior_client_links', $migration);
        self::assertStringContainsString('uq_revizior_client_external (organization_link_id, client_uuid)', $migration);
        self::assertStringContainsString('uq_revizior_client_internal (organization_link_id, client_id)', $migration);
        self::assertStringContainsString('REFERENCES clients(id)', $migration);
        self::assertStringNotContainsString('DROP ', strtoupper($migration));
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($content);
        return $content;
    }
}
