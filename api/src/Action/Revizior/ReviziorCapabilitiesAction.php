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
                // Consumer přešel na kontraktní `user:write`; cross-repo smoke
                // (upsert → změna role → revoke → retry) prošel 2026-09-02.
                'userProvisioning' => true,
                // Sdílený ClientWriter + revizior_client_links (R3, slice 1).
                'clientUpsert' => true,
                'priceResolution' => false,
                // Sdílený InvoiceDraftCreator + revizior_invoice_links (R3, slice 3).
                'invoiceDraft' => true,
                // R6: streamovaný upload PDF s digestem a idempotencí.
                'attachments' => true,
                // R4: jednorázový ticket, session a target/return allowlist.
                'sso' => true,
                'proforma' => false,
                'creditNote' => false,
                'partialPayments' => false,
                // R5: transakční outbox + podepsaný callback.
                'eventOutbox' => true,
            ],
            'limits' => [
                'maxItemsPerInvoice' => 500,
                'maxAttachmentBytes' => 20 * 1024 * 1024,
                'maxRequestBytes' => 2 * 1024 * 1024,
            ],
        ], $request->getHeaderLine(ReviziorServiceAuthMiddleware::REQUEST_ID_HEADER));
    }
}
