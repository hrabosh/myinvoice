<?php

declare(strict_types=1);

namespace MyInvoice\Action\Revizior;

use MyInvoice\Http\ReviziorResponse;
use MyInvoice\Middleware\ReviziorServiceAuthMiddleware;
use MyInvoice\Service\Integration\Revizior\ReviziorAttachmentService;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class PutReviziorInvoiceAttachmentAction
{
    public function __construct(
        private readonly ReviziorAttachmentService $attachments,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $requestId = $request->getHeaderLine(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER);
        try {
            $contentLength = $request->getHeaderLine('Content-Length');
            $result = $this->attachments->store(
                (string) ($args['organizationUuid'] ?? ''),
                (string) ($args['externalInvoiceKey'] ?? ''),
                (string) ($args['externalAttachmentKey'] ?? ''),
                $request->getBody(),
                $request->getHeaderLine('Content-Type'),
                $request->getHeaderLine('Digest'),
                ctype_digit($contentLength) ? (int) $contentLength : null,
                $request->hasHeader('X-File-Name') ? $request->getHeaderLine('X-File-Name') : null,
            );

            return ReviziorResponse::success($response, $result['data'], $requestId, $result['created'] ? 201 : 200);
        } catch (ReviziorProvisioningException $e) {
            return ReviziorResponse::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $requestId, $e->retryable, $e->fields);
        } catch (Throwable $e) {
            // Obsah přílohy se nikdy neloguje — jen to, že se nepodařila.
            $this->logger->error('ReviziOR attachment upload failed', [
                'request_id' => $requestId,
                'exception' => $e::class,
            ]);

            return ReviziorResponse::error($response, 'provider_temporarily_unavailable', 'Fakturační služba je dočasně nedostupná.', 503, $requestId, true);
        }
    }
}
