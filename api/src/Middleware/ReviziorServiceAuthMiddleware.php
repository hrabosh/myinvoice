<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\ReviziorResponse;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use MyInvoice\Service\Revizior\Security\ReviziorReplayGuard;
use MyInvoice\Service\Revizior\Security\ReviziorServiceAuthException;
use MyInvoice\Service\Revizior\Security\ReviziorServiceIdentity;
use MyInvoice\Service\Revizior\Security\ReviziorServiceTokenVerifier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Throwable;

final class ReviziorServiceAuthMiddleware implements MiddlewareInterface
{
    public const PATH_PREFIX = '/api/integrations/revizior/v1';
    public const ATTR_IDENTITY = 'revizior.service_identity';
    public const REQUEST_ID_HEADER = 'X-Request-Id';

    public function __construct(
        private readonly DeploymentCapabilities $capabilities,
        private readonly ReviziorServiceTokenVerifier $verifier,
        private readonly ReviziorReplayGuard $replayGuard,
        private readonly ResponseFactory $responseFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, self::PATH_PREFIX)) {
            return $handler->handle($request);
        }

        $requestId = trim($request->getHeaderLine(self::REQUEST_ID_HEADER));
        try {
            if (!$this->capabilities->isReviziorManaged()) {
                return ReviziorResponse::error(
                    $this->responseFactory->createResponse(404),
                    'managed_integration_disabled',
                    'Integrační API není v tomto deploymentu dostupné.',
                    404,
                    $requestId,
                );
            }

            $authorization = trim($request->getHeaderLine('Authorization'));
            if (!str_starts_with($authorization, 'Bearer ') || strlen($authorization) <= 7) {
                throw ReviziorServiceAuthException::unauthorized('service_token_missing');
            }

            $identity = $this->verifier->verify(substr($authorization, 7), $requestId);
            $this->authorize($request, $identity);
            $this->replayGuard->consume($identity);

            return $handler->handle($request->withAttribute(self::ATTR_IDENTITY, $identity));
        } catch (ReviziorServiceAuthException $e) {
            $this->logger->warning('ReviziOR service request rejected', [
                'request_id' => $requestId,
                'endpoint' => $this->endpointFamily($path),
                'error_code' => $e->errorCode,
            ]);
            return ReviziorResponse::error(
                $this->responseFactory->createResponse($e->httpStatus),
                $e->errorCode,
                $e->getMessage(),
                $e->httpStatus,
                $requestId,
                $e->retryable,
            );
        } catch (Throwable $e) {
            $this->logger->error('ReviziOR service authentication failed', [
                'request_id' => $requestId,
                'endpoint' => $this->endpointFamily($path),
                'exception' => $e::class,
            ]);
            return ReviziorResponse::error(
                $this->responseFactory->createResponse(503),
                'provider_temporarily_unavailable',
                'Fakturační služba je dočasně nedostupná.',
                503,
                $requestId,
                true,
            );
        }
    }

    private function authorize(Request $request, ReviziorServiceIdentity $identity): void
    {
        $path = $request->getUri()->getPath();
        if ($request->getMethod() === 'GET' && $path === self::PATH_PREFIX . '/capabilities') {
            // Consumer dočasně používá provisioning assertion i pro první
            // capability probe. Nový scope je už autoritativní, silnější
            // provisioning scope zůstává kompatibilní pouze pro tento read.
            if (!$identity->hasScope('capabilities:read')
                && !$identity->hasScope('organization:provision')
            ) {
                throw ReviziorServiceAuthException::forbidden('service_scope_insufficient');
            }
            if ($identity->subject !== 'platform') {
                throw ReviziorServiceAuthException::forbidden('organization_subject_mismatch');
            }
            return;
        }

        throw ReviziorServiceAuthException::forbidden('service_scope_insufficient');
    }

    private function endpointFamily(string $path): string
    {
        return $path === self::PATH_PREFIX . '/capabilities'
            ? '/capabilities'
            : '/unsupported';
    }
}
