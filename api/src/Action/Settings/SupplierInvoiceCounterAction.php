<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\Permission;
use MyInvoice\Service\Auth\PermissionPolicy;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/settings/supplier/invoice-counter — nastaví counter číselné řady
 * uživateli se supplier_settings.manage.
 *
 * Body: { "type": "invoice"|"proforma"|"credit_note", "next_number": 42, "date": "2026-07-01"? }
 *
 * Nastaví supplier-wide counter tak, aby PŘÍŠTÍ vystavený doklad daného typu dostal
 * číslo `next_number`. `date` určuje, do kterého období (dle `invoice_number_period`)
 * se counter zapíše — default dnes. Umí counter i snížit; pokud by nové číslo
 * kolidovalo s už vystaveným dokladem, vystavení se samoopravně posune na první
 * volné číslo (viz VarsymbolGenerator::next()).
 *
 * Typický use-case: napojení externího systému, který přebírá existující číselnou
 * řadu (import historie, migrace z jiného fakturačního software).
 *
 * Response: { "type": "invoice", "next_number": 42, "counter": 41,
 *             "period": "202607", "preview": "2607042" }
 */
final class SupplierInvoiceCounterAction
{
    public function __construct(
        private readonly VarsymbolGenerator $varsymbol,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PermissionPolicy $permissions,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->permissions->allows($request, Permission::SupplierSettingsManage)) {
            return Json::error($response, 'forbidden', 'Pro správu nastavení firmy nemáš oprávnění.', 403);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolen dodavatel.', 400);
        }

        $b    = (array) ($request->getParsedBody() ?? []);
        $type = (string) ($b['type'] ?? 'invoice');
        if (!in_array($type, ['invoice', 'proforma', 'credit_note'], true)) {
            return Json::error($response, 'validation_failed', "Neplatný type (invoice|proforma|credit_note).", 400);
        }

        $next = (int) ($b['next_number'] ?? 0);
        if ($next < 1 || $next > 999999999) {
            return Json::error($response, 'validation_failed', 'next_number musí být celé číslo 1–999999999.', 400);
        }

        $for = null;
        if (trim((string) ($b['date'] ?? '')) !== '') {
            try {
                $for = new \DateTimeImmutable((string) $b['date']);
            } catch (\Throwable) {
                return Json::error($response, 'validation_failed', 'date musí být platné datum (YYYY-MM-DD).', 400);
            }
        }

        try {
            $result = $this->varsymbol->setCounter($supplierId, $type, $next, $for);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            'supplier.invoice_counter_set',
            (int) ($user['id'] ?? 0),
            'supplier',
            $supplierId,
            ['type' => $type, 'next_number' => $next, 'period' => $result['period']],
            $ip,
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, [
            'type'        => $type,
            'next_number' => $next,
            'counter'     => $result['counter'],
            'period'      => $result['period'],
            'preview'     => $result['preview'],
        ]);
    }
}
