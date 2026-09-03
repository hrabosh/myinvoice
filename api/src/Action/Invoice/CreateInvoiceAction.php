<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Invoice\InvoiceDraftCreator;
use MyInvoice\Service\Invoice\InvoiceDraftException;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\WriteActor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateInvoiceAction
{
    public function __construct(
        private readonly InvoiceDraftCreator $creator,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $actor = new WriteActor(
            isset($user['id']) ? (int) $user['id'] : null,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        try {
            $result = $this->creator->create(SupplierGuard::currentId($request), $body, $actor);
        } catch (InvoiceDraftException $e) {
            return match ($e->kind) {
                InvoiceDraftException::KIND_VALIDATION => Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $e->fields]),
                InvoiceDraftException::KIND_CLIENT_NOT_FOUND => Json::error($response, 'client_not_found', 'Klient neexistuje.', 400),
                InvoiceDraftException::KIND_VARSYMBOL_DUPLICATE => Json::error($response, 'varsymbol_duplicate', $e->getMessage(), 409),
                default => Json::error($response, 'integrity_violation', $e->getMessage(), 400),
            };
        }

        $invoice = $result['invoice'];
        if ($result['exchange_rate'] !== null) {
            $invoice['_meta'] = ['exchange_rate' => $result['exchange_rate']];
        }
        return Json::ok($response, $invoice, 201);
    }
}
