<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\ReviziorServiceAuthMiddleware;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use MyInvoice\Service\Revizior\Security\ReviziorReplayGuard;
use MyInvoice\Service\Revizior\Security\ReviziorServiceAuthException;
use MyInvoice\Service\Revizior\Security\ReviziorServiceIdentity;
use MyInvoice\Service\Revizior\Security\ReviziorServiceTokenVerifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ReviziorServiceAuthMiddlewareTest extends TestCase
{
    private const REQUEST_ID = '10000000-0000-4000-8000-000000000001';

    private ReviziorServiceTokenVerifier&MockObject $verifier;
    private ReviziorReplayGuard&MockObject $replay;

    protected function setUp(): void
    {
        $this->verifier = $this->createMock(ReviziorServiceTokenVerifier::class);
        $this->replay = $this->createMock(ReviziorReplayGuard::class);
    }

    public function testMissingAndInvalidBearerNeverReachTheAction(): void
    {
        $this->verifier->expects(self::never())->method('verify');
        $this->replay->expects(self::never())->method('consume');
        $response = $this->middleware()->process($this->request(), $this->failingHandler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('service_token_missing', $this->json($response)['error']['code']);

        $verifier = $this->createMock(ReviziorServiceTokenVerifier::class);
        $verifier->expects(self::once())
            ->method('verify')
            ->willThrowException(ReviziorServiceAuthException::unauthorized());
        $response = $this->middleware($verifier)->process(
            $this->request()->withHeader('Authorization', 'Bearer mi_pat_not_a_service_assertion'),
            $this->failingHandler(),
        );
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('service_token_invalid', $this->json($response)['error']['code']);
    }

    public function testValidPlatformAssertionIsConsumedBeforeAction(): void
    {
        $identity = $this->identity(['capabilities:read']);
        $this->verifier->expects(self::once())
            ->method('verify')
            ->with('signed-token', self::REQUEST_ID)
            ->willReturn($identity);
        $this->replay->expects(self::once())->method('consume')->with($identity);

        $response = $this->middleware()->process(
            $this->request()->withHeader('Authorization', 'Bearer signed-token'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    if (!$request->getAttribute(ReviziorServiceAuthMiddleware::ATTR_IDENTITY) instanceof ReviziorServiceIdentity) {
                        throw new \RuntimeException('Service identity chybí.');
                    }
                    return (new ResponseFactory())->createResponse(204);
                }
            },
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testInsufficientScopeIsRejectedBeforeReplayInsert(): void
    {
        $this->verifier->expects(self::once())
            ->method('verify')
            ->willReturn($this->identity(['invoice:read']));
        $this->replay->expects(self::never())->method('consume');

        $response = $this->middleware()->process(
            $this->request()->withHeader('Authorization', 'Bearer signed-token'),
            $this->failingHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('service_scope_insufficient', $this->json($response)['error']['code']);
    }

    public function testProvisioningScopeDoesNotOpenCapabilitiesRead(): void
    {
        $this->verifier->expects(self::once())
            ->method('verify')
            ->willReturn($this->identity(['organization:provision']));
        $this->replay->expects(self::never())->method('consume');

        $response = $this->middleware()->process(
            $this->request()->withHeader('Authorization', 'Bearer signed-token'),
            $this->failingHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('service_scope_insufficient', $this->json($response)['error']['code']);
    }

    public function testOrganizationProvisionRequiresPlatformSubjectAndDedicatedScope(): void
    {
        $identity = $this->identity(['organization:provision']);
        $this->verifier->expects(self::once())->method('verify')->willReturn($identity);
        $this->replay->expects(self::once())->method('consume')->with($identity);

        $response = $this->middleware()->process(
            $this->provisionRequest()->withHeader('Authorization', 'Bearer signed-token'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return (new ResponseFactory())->createResponse(204);
                }
            },
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testTenantSubjectCannotProvisionOrganization(): void
    {
        $identity = new ReviziorServiceIdentity(
            'https://app.revizior.cz',
            '30000000-0000-4000-8000-000000000001',
            '20000000-0000-4000-8000-000000000001',
            time() + 60,
            ['organization:provision'],
            self::REQUEST_ID,
        );
        $this->verifier->expects(self::once())->method('verify')->willReturn($identity);
        $this->replay->expects(self::never())->method('consume');

        $response = $this->middleware()->process(
            $this->provisionRequest()->withHeader('Authorization', 'Bearer signed-token'),
            $this->failingHandler(),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('organization_subject_mismatch', $this->json($response)['error']['code']);
    }

    public function testOrganizationUpdateRequiresMatchingTenantAndOrganizationWriteScope(): void
    {
        $identity = $this->tenantIdentity(['organization:write']);
        $this->verifier->expects(self::once())->method('verify')->willReturn($identity);
        $this->replay->expects(self::once())->method('consume')->with($identity);

        $response = $this->middleware()->process(
            $this->tenantRequest('PUT', '/organizations/30000000-0000-4000-8000-000000000001')
                ->withHeader('Authorization', 'Bearer signed-token'),
            $this->acceptingHandler(),
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testOrganizationUpdateRejectsDifferentTenantSubject(): void
    {
        $identity = $this->tenantIdentity(['organization:write'], '30000000-0000-4000-8000-000000000099');
        $this->verifier->expects(self::once())->method('verify')->willReturn($identity);
        $this->replay->expects(self::never())->method('consume');

        $response = $this->middleware()->process(
            $this->tenantRequest('PUT', '/organizations/30000000-0000-4000-8000-000000000001')
                ->withHeader('Authorization', 'Bearer signed-token'),
            $this->failingHandler(),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('organization_subject_mismatch', $this->json($response)['error']['code']);
    }

    public function testUserSynchronizationRequiresDedicatedUserWriteScope(): void
    {
        $identity = $this->tenantIdentity(['organization:write']);
        $this->verifier->expects(self::once())->method('verify')->willReturn($identity);
        $this->replay->expects(self::never())->method('consume');

        $response = $this->middleware()->process(
            $this->tenantRequest(
                'PUT',
                '/organizations/30000000-0000-4000-8000-000000000001/users/20000000-0000-4000-8000-000000000001',
            )->withHeader('Authorization', 'Bearer signed-token'),
            $this->failingHandler(),
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('service_scope_insufficient', $this->json($response)['error']['code']);
    }

    public function testUserRevokeAcceptsDedicatedScopeForMatchingTenant(): void
    {
        $identity = $this->tenantIdentity(['user:write']);
        $this->verifier->expects(self::once())->method('verify')->willReturn($identity);
        $this->replay->expects(self::once())->method('consume')->with($identity);

        $response = $this->middleware()->process(
            $this->tenantRequest(
                'DELETE',
                '/organizations/30000000-0000-4000-8000-000000000001/users/20000000-0000-4000-8000-000000000001',
            )->withHeader('Authorization', 'Bearer signed-token'),
            $this->acceptingHandler(),
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testStandaloneDeploymentHidesIntegrationNamespace(): void
    {
        $this->verifier->expects(self::never())->method('verify');
        $this->replay->expects(self::never())->method('consume');
        $response = $this->middleware(capabilities: new DeploymentCapabilities(new Config([])))
            ->process(
                $this->request()->withHeader('Authorization', 'Bearer signed-token'),
                $this->failingHandler(),
            );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('managed_integration_disabled', $this->json($response)['error']['code']);
    }

    private function middleware(
        ?ReviziorServiceTokenVerifier $verifier = null,
        ?DeploymentCapabilities $capabilities = null,
    ): ReviziorServiceAuthMiddleware {
        return new ReviziorServiceAuthMiddleware(
            $capabilities ?? new DeploymentCapabilities(new Config([
                'deployment' => [
                    'mode' => 'revizior_managed',
                    'public_name' => 'ReviziOR Fakturace',
                    'revizior' => ['app_url' => 'https://app.revizior.cz/fakturace'],
                ],
            ])),
            $verifier ?? $this->verifier,
            $this->replay,
            new ResponseFactory(),
            new NullLogger(),
        );
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/integrations/revizior/v1/capabilities')
            ->withHeader(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER, self::REQUEST_ID);
    }

    private function provisionRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'POST',
                '/api/integrations/revizior/v1/organizations/30000000-0000-4000-8000-000000000001/provision',
            )
            ->withHeader(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER, self::REQUEST_ID);
    }

    private function tenantRequest(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, ReviziorServiceAuthMiddleware::PATH_PREFIX . $path)
            ->withHeader(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER, self::REQUEST_ID);
    }

    private function identity(array $scopes): ReviziorServiceIdentity
    {
        return new ReviziorServiceIdentity(
            'https://app.revizior.cz',
            'platform',
            '20000000-0000-4000-8000-000000000001',
            time() + 60,
            $scopes,
            self::REQUEST_ID,
        );
    }

    /** @param list<string> $scopes */
    private function tenantIdentity(
        array $scopes,
        string $subject = '30000000-0000-4000-8000-000000000001',
    ): ReviziorServiceIdentity {
        return new ReviziorServiceIdentity(
            'https://app.revizior.cz',
            $subject,
            '20000000-0000-4000-8000-000000000001',
            time() + 60,
            $scopes,
            self::REQUEST_ID,
        );
    }

    private function acceptingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }

    private function failingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Action nesmí být zavolána.');
            }
        };
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
    }
}
