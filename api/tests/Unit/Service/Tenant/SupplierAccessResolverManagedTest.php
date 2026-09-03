<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tenant;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierAccessResolverManagedTest extends TestCase
{
    public function testManagedUserWithoutMembershipIsDeniedWithoutSupplierFallback(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::once())->method('assignmentsForUser')->with(17)->willReturn([]);

        $resolver = new SupplierAccessResolver($db, $memberships, $this->managedCapabilities());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'accountant']);

        $access = $resolver->resolve($request);

        self::assertTrue($access->denied);
        self::assertSame(0, $access->supplierId);
        self::assertNull($access->roleOverride);
    }

    public function testManagedBoundTokenWithoutMembershipIsDenied(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::once())->method('assignmentsForUser')->with(17)->willReturn([]);

        $resolver = new SupplierAccessResolver($db, $memberships, $this->managedCapabilities());
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/invoices')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => 42]);

        $access = $resolver->resolve($request);

        self::assertTrue($access->denied);
        self::assertSame(42, $access->supplierId);
    }

    private function managedCapabilities(): DeploymentCapabilities
    {
        return new DeploymentCapabilities(new Config([
            'deployment' => [
                'mode' => 'revizior_managed',
                'public_name' => 'ReviziOR Fakturace',
                'revizior' => ['app_url' => 'https://app.revizior.cz/fakturace'],
            ],
        ]));
    }
}
