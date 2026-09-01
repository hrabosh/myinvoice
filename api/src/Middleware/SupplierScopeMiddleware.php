<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Multi-supplier scope: čte hlavičku `X-Supplier-Id` (z Pinia stores na FE) a
 * vystaví ji jako request attribute. Akce čtou přes:
 *
 *   $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
 *
 * Pravidla (resoluci sdílí SupplierAccessResolver — používá ji i RoleMiddleware
 * pro efektivní per-supplier roli):
 *   - PAT bound na supplier_id → forcuj ho, header/query se ignoruje
 *   - Pokud header chybí nebo není v DB, fallback = MIN(supplier.id), resp.
 *     nejnižší PŘIŘAZENÝ supplier u uživatele s membership (user_suppliers)
 *   - Uživatel s neprázdným membership, který si explicitně vyžádá firmu mimo
 *     své membership → 403 `forbidden_supplier` (dřív směl kamkoliv)
 *   - Uživatel bez membership řádků = bez omezení (zpětná kompatibilita)
 *   - Pokud supplier tabulka prázdná (před setup) → 0 (akce by stejně měly být chráněné Authem)
 *   - Validace se memoizuje v rámci requestu (resolver)
 */
final class SupplierScopeMiddleware implements MiddlewareInterface
{
    public const ATTR_CURRENT_ID = 'supplier.current_id';
    public const HEADER_NAME     = 'X-Supplier-Id';

    public function __construct(
        private readonly SupplierAccessResolver $resolver,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/auth/webauthn/')
            || str_starts_with($path, '/api/auth/mfa/')
            || str_starts_with($path, '/api/auth/session/')
        ) {
            return $handler->handle($request);
        }

        $access = $this->resolver->resolve($request);

        if ($access->denied) {
            // Uživatel bez managed membershipu musí stále načíst vlastní stav a
            // umět se bezpečně odhlásit. Supplier-scoped data však nedostane.
            if (in_array($path, RoleMiddleware::PUBLIC_OR_SELF, true)
                || str_starts_with($path, '/api/public/')
            ) {
                return $handler->handle($request->withAttribute(self::ATTR_CURRENT_ID, 0));
            }
            $response = $this->responseFactory->createResponse(403);
            return Json::error($response, 'forbidden_supplier', 'K této firmě nemáš oprávnění.', 403);
        }

        return $handler->handle(
            $request->withAttribute(self::ATTR_CURRENT_ID, $access->supplierId),
        );
    }
}
