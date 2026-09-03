<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorTenantSynchronizationContractTest extends TestCase
{
    public function testTenantRoutesUseDedicatedScopesAndMatchingSubject(): void
    {
        $routes = $this->read('api/src/Routes.php');
        $middleware = $this->read('api/src/Middleware/ReviziorServiceAuthMiddleware.php');

        self::assertStringContainsString(
            "'/api/integrations/revizior/v1/organizations/{organizationUuid}'",
            $routes,
        );
        self::assertStringContainsString(
            "'/api/integrations/revizior/v1/organizations/{organizationUuid}/users/{userUuid}'",
            $routes,
        );
        self::assertStringContainsString("'organization:write'", $middleware);
        self::assertStringContainsString("'user:write'", $middleware);
        self::assertStringContainsString('identity->subject', $middleware);
    }

    public function testUserSynchronizationIsTransactionalAndNeverDeletesTheUser(): void
    {
        $service = $this->read('api/src/Service/Integration/Revizior/ReviziorUserProvisioner.php');

        self::assertStringContainsString('beginTransaction()', $service);
        self::assertStringContainsString('revizior_user_links', $service);
        self::assertStringContainsString('user_suppliers', $service);
        self::assertStringContainsString('session_version = session_version + 1', $service);
        self::assertStringContainsString('revizior.user.revoked', $service);
        self::assertStringNotContainsString('DELETE FROM users', $service);
        self::assertStringNotContainsString("role = 'admin'", $service);
    }

    public function testMigrationAndBothOpenApiDocumentsCoverUserSynchronization(): void
    {
        $migration = $this->read('db/migrations/0153_revizior_user_provisioning.sql');
        $path = '/api/integrations/revizior/v1/organizations/{organizationUuid}/users/{userUuid}';

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS payload_hash', $migration);
        self::assertStringNotContainsString('DROP ', strtoupper($migration));
        self::assertStringContainsString($path, $this->read('api/openapi.yaml'));
        self::assertStringContainsString($path, $this->read('api/openapi-revizior-integration.yaml'));
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($content);
        return $content;
    }
}
