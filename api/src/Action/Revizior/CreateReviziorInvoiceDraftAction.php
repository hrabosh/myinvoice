<?php

declare(strict_types=1);

namespace MyInvoice\Action\Revizior;

use MyInvoice\Http\ReviziorResponse;
use MyInvoice\Middleware\ReviziorServiceAuthMiddleware;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftService;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class CreateReviziorInvoiceDraftAction
{
    public function __construct(
        private readonly ReviziorInvoiceDraftService $service,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $requestId = $request->getHeaderLine(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER);
        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                throw ReviziorProvisioningException::validation(['body' => 'required_json_object']);
            }
            $result = $this->service->create(
                (string) ($args['organizationUuid'] ?? ''),
                $body,
                trim($request->getHeaderLine('Idempotency-Key')),
            );
            return ReviziorResponse::success($response, $result->data, $requestId, $result->created ? 201 : 200);
        } catch (ReviziorProvisioningException $e) {
            return ReviziorResponse::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $requestId, $e->retryable, $e->fields);
        } catch (Throwable $e) {
            $this->logger->error('ReviziOR invoice draft failed', ['request_id' => $requestId, 'exception' => $e::class]);
            return ReviziorResponse::error($response, 'provider_temporarily_unavailable', 'Fakturační služba je dočasně nedostupná.', 503, $requestId, true);
        }
    }
}
