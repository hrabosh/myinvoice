<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SupplierScopeManagedTest extends TestCase
{
    public function testDeniedMembershipCanStillReadOwnSessionStateWithoutSupplierScope(): void
    {
        $middleware = $this->deniedMiddleware();
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/me'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return (new ResponseFactory())->createResponse(204)
                        ->withHeader('X-Test-Supplier', (string) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, -1));
                }
            },
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('0', $response->getHeaderLine('X-Test-Supplier'));
    }

    public function testDeniedMembershipCannotReachSupplierData(): void
    {
        $response = $this->deniedMiddleware()->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return (new ResponseFactory())->createResponse(204);
                }
            },
        );

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('forbidden_supplier', $body['error']['code']);
    }

    private function deniedMiddleware(): SupplierScopeMiddleware
    {
        $resolver = $this->createStub(SupplierAccessResolver::class);
        $resolver->method('resolve')->willReturn(new SupplierAccess(0, true, null));
        return new SupplierScopeMiddleware($resolver, new ResponseFactory());
    }
}
