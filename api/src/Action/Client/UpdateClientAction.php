<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\WriteActor;
use MyInvoice\Service\Client\ClientWriteException;
use MyInvoice\Service\Client\ClientWriter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateClientAction
{
    public function __construct(
        private readonly ClientRepository $repo,
        private readonly ClientWriter $writer,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if (!SupplierGuard::owns($request, $this->repo->find($id))) {
            return Json::error($response, 'not_found', 'Klient nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $actor = new WriteActor(
            isset($user['id']) ? (int) $user['id'] : null,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        try {
            $result = $this->writer->update($id, $supplierId, $body, $actor);
        } catch (ClientWriteException $e) {
            return ClientWriteResponse::error($response, $e);
        }

        // *_category_backfilled = počet faktur, do kterých byla doplněna nově nastavená
        // výchozí kategorie nákladu / tržby (frontend ukáže toast).
        $client = $result['client'];
        $client['expense_category_backfilled'] = $result['backfilled']['expense'];
        $client['revenue_category_backfilled'] = $result['backfilled']['revenue'];
        return Json::ok($response, $client);
    }
}
