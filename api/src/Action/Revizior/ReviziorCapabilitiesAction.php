<?php

declare(strict_types=1);

namespace MyInvoice\Action\Revizior;

use MyInvoice\Http\ReviziorResponse;
use MyInvoice\Middleware\ReviziorServiceAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ReviziorCapabilitiesAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        return ReviziorResponse::success($response, [
            'contractVersion' => ReviziorResponse::CONTRACT_VERSION,
            'deploymentMode' => 'revizior_managed',
            // Capability znamená hotový end-to-end endpoint, ne schopnost
            // původní MyInvoice domény. R2 je bude zapínat po jednotlivých slice.
            'features' => [
                'organizationProvisioning' => true,
                'userProvisioning' => false,
                'clientUpsert' => false,
                'priceResolution' => false,
                'invoiceDraft' => false,
                'attachments' => false,
                'sso' => false,
                'proforma' => false,
                'creditNote' => false,
                'partialPayments' => false,
                'eventOutbox' => false,
            ],
            'limits' => [
                'maxItemsPerInvoice' => 500,
                'maxAttachmentBytes' => 20 * 1024 * 1024,
                'maxRequestBytes' => 2 * 1024 * 1024,
            ],
        ], $request->getHeaderLine(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER));
    }
}
