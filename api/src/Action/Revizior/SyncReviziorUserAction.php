<?php

declare(strict_types=1);

namespace MyInvoice\Action\Revizior;

use MyInvoice\Http\ReviziorResponse;
use MyInvoice\Middleware\ReviziorServiceAuthMiddleware;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use MyInvoice\Service\Integration\Revizior\ReviziorUserProvisioner;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class SyncReviziorUserAction
{
    public function __construct(
        private readonly ReviziorUserProvisioner $provisioner,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string,string> $args */
    public function upsert(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, $args, false);
    }

    /** @param array<string,string> $args */
    public function revoke(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, $args, true);
    }

    /** @param array<string,string> $args */
    private function handle(Request $request, Response $response, array $args, bool $revoke): Response
    {
        $requestId = $request->getHeaderLine(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER);
        try {
            $organizationUuid = (string) ($args['organizationUuid'] ?? '');
            $userUuid = (string) ($args['userUuid'] ?? '');
            if ($revoke) {
                $this->provisioner->revoke($organizationUuid, $userUuid);
                return ReviziorResponse::noContent($response, $requestId);
            }

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                throw ReviziorProvisioningException::validation(['body' => 'required_json_object']);
            }
            $result = $this->provisioner->upsert($organizationUuid, $userUuid, $body);
            return ReviziorResponse::success(
                $response,
                $result->data,
                $requestId,
                $result->created ? 201 : 200,
            );
        } catch (ReviziorProvisioningException $e) {
            return ReviziorResponse::error(
                $response,
                $e->errorCode,
                $e->getMessage(),
                $e->httpStatus,
                $requestId,
                $e->retryable,
                $e->fields,
            );
        } catch (Throwable $e) {
            $this->logger->error('ReviziOR user synchronization failed', [
                'request_id' => $requestId,
                'operation' => $revoke ? 'revoke' : 'upsert',
                'exception' => $e::class,
            ]);
            return ReviziorResponse::error(
                $response,
                'provider_temporarily_unavailable',
                'Fakturační služba je dočasně nedostupná.',
                503,
                $requestId,
                true,
            );
        }
    }
}
