<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceSourceReader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `GET /api/invoices/{id}/revizior-sources` — z čeho doklad vznikl.
 *
 * Ve standalone instalaci endpoint neexistuje (`404`), aby o režimu nasazení
 * nic neprozrazoval. Doklad musí patřit aktuální firmě; odpověď je prázdný
 * seznam u dokladu, který v ReviziORu původ nemá.
 */
final class ReviziorSourcesAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ReviziorInvoiceSourceReader $sources,
        private readonly DeploymentCapabilities $capabilities,
    ) {}

    /** @param array<string,string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!$this->capabilities->isReviziorManaged()) {
            return Json::error($response, 'not_found', 'Nenalezeno.', 404);
        }

        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        return Json::ok($response, [
            'sources' => $this->sources->forInvoice($id, SupplierGuard::currentId($request)),
        ]);
    }
}
