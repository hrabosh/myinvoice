<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorOrganizationProvisioningContractTest extends TestCase
{
    public function testProvisioningRouteAndCapabilityAreEnabledTogether(): void
    {
        $routes = $this->read('api/src/Routes.php');
        $capabilities = $this->read('api/src/Action/Revizior/ReviziorCapabilitiesAction.php');
        $middleware = $this->read('api/src/Middleware/ReviziorServiceAuthMiddleware.php');

        self::assertStringContainsString("'/api/integrations/revizior/v1/organizations/{organizationUuid}/provision'", $routes);
        self::assertStringContainsString("'organizationProvisioning' => true", $capabilities);
        self::assertStringContainsString("hasScope('organization:provision')", $middleware);
        self::assertStringContainsString("identity->subject !== 'platform'", $middleware);
    }

    public function testProvisioningPersistsAllRequiredRowsInOneTransaction(): void
    {
        $service = $this->read('api/src/Service/Integration/Revizior/ReviziorOrganizationProvisioner.php');

        self::assertStringContainsString('beginTransaction()', $service);
        self::assertStringContainsString('revizior_idempotency_keys', $service);
        self::assertStringContainsString('revizior_organization_links', $service);
        self::assertStringContainsString('revizior_user_links', $service);
        self::assertStringContainsString('user_suppliers', $service);
        self::assertStringContainsString('revizior.organization.provisioned', $service);
        self::assertStringContainsString('readonly', $service);
        self::assertStringContainsString('supplier_owner', $service);
        self::assertStringNotContainsString('INSERT IGNORE', $service);
    }

    public function testBothOpenApiDocumentsContainRuntimeProvisioningPath(): void
    {
        $path = '/api/integrations/revizior/v1/organizations/{organizationUuid}/provision';
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
