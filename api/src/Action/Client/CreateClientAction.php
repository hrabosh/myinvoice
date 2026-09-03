<?php

declare(strict_types=1);

namespace MyInvoice\Action\Client;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\WriteActor;
use MyInvoice\Service\Client\ClientWriteException;
use MyInvoice\Service\Client\ClientWriter;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateClientAction
{
    public function __construct(
        private readonly ClientWriter $writer,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Nejdřív supplier kontext: bez dodavatele jsou currencies prázdné a klientský
        // formulář by spadl na matoucí „Validace selhala" (currency_default_id=0). Vrať
        // jasnou, akční hlášku místo toho (#151). FE onboarding gate sem uživatele nepustí.
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            return Json::error(
                $response,
                'no_supplier',
                'Nelze vytvořit klienta — nejdříve vytvořte dodavatele (Nastavení → Číselníky → Dodavatelé).',
                400,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $actor = new WriteActor(
            isset($user['id']) ? (int) $user['id'] : null,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        try {
            $client = $this->writer->create($supplierId, $body, $actor);
        } catch (ClientWriteException $e) {
            return ClientWriteResponse::error($response, $e);
        }

        return Json::ok($response, $client, 201);
    }
}
