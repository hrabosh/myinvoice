<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\ManagedModeGuardMiddleware;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ManagedModeGuardMiddlewareTest extends TestCase
{
    #[DataProvider('managedDeniedPaths')]
    public function testManagedModeDeniesPublicBootstrapAndDisabledFeatures(string $path, string $code): void
    {
        $response = $this->managed()->process($this->request($path), $this->okHandler());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame($code, $body['error']['code']);
    }

    /** @return iterable<string, array{string,string}> */
    public static function managedDeniedPaths(): iterable
    {
        yield 'setup' => ['/api/auth/setup', 'managed_setup_disabled'];
        yield 'setup helper' => ['/api/auth/setup-ares-lookup', 'managed_setup_disabled'];
        yield 'login' => ['/api/auth/login', 'managed_local_auth_disabled'];
        yield 'passkey login' => ['/api/auth/webauthn/login/verify', 'managed_local_auth_disabled'];
        yield 'forgot password' => ['/api/auth/forgot', 'managed_local_auth_disabled'];
        yield 'self update' => ['/api/admin/update/trigger', 'managed_self_update_disabled'];
        yield 'MyUcto upgrade' => ['/api/admin/myucto-upgrade/status', 'managed_myucto_upgrade_disabled'];
        yield 'purchase invoices' => ['/api/purchase-invoices', 'managed_module_disabled'];
        yield 'purchase dashboard' => ['/api/dashboard/purchase-summary', 'managed_module_disabled'];
        yield 'tax reports' => ['/api/reports/dph', 'managed_module_disabled'];
        yield 'logbook' => ['/api/logbook/trips', 'managed_module_disabled'];
    }

    public function testManagedModeAllowsStatusAndEnabledModules(): void
    {
        foreach (['/api/auth/setup-status', '/api/invoices', '/api/documents'] as $path) {
            self::assertSame(
                204,
                $this->managed()->process($this->request($path), $this->okHandler())->getStatusCode(),
                $path,
            );
        }
    }

    public function testStandalonePassesEveryPathThrough(): void
    {
        $middleware = new ManagedModeGuardMiddleware(
            new DeploymentCapabilities(new Config([])),
            new ResponseFactory(),
        );

        foreach (['/api/auth/setup', '/api/auth/login', '/api/admin/update/trigger', '/api/reports/dph'] as $path) {
            self::assertSame(204, $middleware->process($this->request($path), $this->okHandler())->getStatusCode());
        }
    }

    private function managed(): ManagedModeGuardMiddleware
    {
        return new ManagedModeGuardMiddleware(
            new DeploymentCapabilities(new Config([
                'deployment' => [
                    'mode' => 'revizior_managed',
                    'public_name' => 'ReviziOR Fakturace',
                    'revizior' => ['app_url' => 'https://app.revizior.cz/fakturace'],
                ],
            ])),
            new ResponseFactory(),
        );
    }

    private function request(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
