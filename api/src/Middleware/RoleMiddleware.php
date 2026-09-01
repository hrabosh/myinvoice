<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Service\Auth\Permission;
use MyInvoice\Service\Auth\PermissionPolicy;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Role-based access control (RBAC).
 *
 * Hierarchie: admin > accountant > readonly. Authorization model:
 *
 *   - readonly:  GET kdekoliv + vlastní účet (logout, change-password, totp/*)
 *   - accountant: vše co readonly + mutace na business datech (clients, projects,
 *                 invoices, work-reports, bank-statements/transactions, ARES/VIES lookup)
 *   - admin:     vše + admin endpointy (users, settings, codebooks, email-templates,
 *                activity-log, invoices-zip, bank/scan)
 *
 * AuthMiddleware už zajistil, že je uživatel přihlášen (jinak public path).
 * Tento middleware běží PO Auth a kontroluje minimální roli pro danou kombinaci
 * method+path.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /** Cesty, kde RBAC neaplikujeme (public + self-service). */
    public const PUBLIC_OR_SELF = [
        '/api/health',
        '/api/version',
        '/api/openapi.yaml',
        '/api/docs',
        '/api/reference',
        '/api/scalar',
        '/api/auth/setup-status',
        '/api/auth/setup',
        '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup',
        '/api/auth/setup-sample',
        '/api/auth/login',
        '/api/auth/webauthn/login/options',
        '/api/auth/webauthn/login/verify',
        '/api/auth/logout',
        '/api/auth/me',
        '/api/auth/forgot',
        '/api/auth/reset',
        '/api/auth/change-password',
        '/api/auth/totp/status',
        '/api/auth/totp/setup',
        '/api/auth/totp/enable',
        '/api/auth/webauthn/credentials',
        '/api/auth/webauthn/register/options',
        '/api/auth/webauthn/register/verify',
        '/api/auth/webauthn/step-up/options',
        '/api/auth/webauthn/step-up/verify',
        '/api/auth/mfa/step-up/totp',
        '/api/auth/mfa/step-up/recovery',
        // Vlastní záložní kódy spravuje jen jejich majitel — generování si navíc
        // vynucuje čerstvý step-up skutečným faktorem (MfaRecoveryCodeAction).
        '/api/auth/mfa/recovery-codes',
        '/api/auth/session/status',
        '/api/auth/session/activity',
        '/api/auth/session/lock',
        '/api/auth/session/lock-preference',
        '/api/auth/session/unlock/options',
        '/api/auth/session/unlock/verify',
        '/api/csrf-token',
    ];

    /**
     * Endpointy, které vyžadují roli 'accountant' nebo vyšší (povolují i admin).
     * Pokud není match, fallback je 'admin'.
     *
     * Formát: ['METHOD path-regex']
     * Method může být '*' pro libovolnou.
     */
    private const ACCOUNTANT_RULES = [
        // Klienti, zakázky, faktury, výkazy, banka — plná CRUD
        '* #^/api/clients(/|$)#',
        '* #^/api/projects(/|$)#',
        '* #^/api/invoices(/|$)#',
        '* #^/api/recurring(/|$)#',
        // Přijaté faktury — účetní smí plnou CRUD (vč. items, PDF, transition,
        // payment-qr, link-advance). Bez tohoto pravidla padaly všechny non-GET
        // na purchase-invoices do admin-only fallbacku (funkční mezera).
        '* #^/api/purchase-invoices(/|$)#',
        '* #^/api/work-reports(/|$)#',
        '* #^/api/bank-statements(/|$)#',
        '* #^/api/bank-transactions(/|$)#',
        // Dokumenty — účetní smí zakládat/upravovat/mazat (do koše) + spravovat složky
        '* #^/api/documents(/|$)#',
        '* #^/api/document-folders(/|$)#',
        // Kniha jízd — účetní smí plnou CRUD (auta, jízdy, tankování, kategorie, import, sken faktur)
        '* #^/api/logbook(/|$)#',
        // Codebooks read-only přes API (admin endpointy mají zvláštní cestu /api/admin/codebooks)
        'GET #^/api/codebooks(/|$)#',
        'GET #^/api/price-list-items(/|$)#',
        // Vlastní podpisové profily účetních; Action vrstva hlídá feature flag i owner_user_id.
        '* #^/api/settings/signing/profiles(/|$)#',
        '* #^/api/settings/pdf-signing/user-defaults(/|$)#',
        // Čtení globálního nastavení podepisování (SigningProfilesAction::settings = admin|accountant)
        'GET #^/api/settings/signing$#',
        // ZIP export + stav import jobu může i účetní (read)
        'GET #^/api/admin/invoices-zip$#',
        'GET #^/api/admin/imports/[0-9]+$#',
        // Import dokladů = práce s daty, ne konfigurace — Action vrstva to tak deklaruje
        // (ImportAction, StartIdoklad/FakturoidImportAction, AiExtractPdfAction,
        // Cancel/DeleteImportJobAction = admin|accountant) a stejně to slibuje manuál
        // (39.5 RBAC). Bez těchto pravidel účetnímu spadl každý běh importu do
        // admin-only fallbacku dřív, než se Action guard vůbec dostal ke slovu.
        // Credentials integrací (PUT/DELETE …/credentials) zůstávají admin-only —
        // to je konfigurace systému, ne data.
        'POST #^/api/admin/import$#',
        'POST #^/api/admin/imports/(idoklad|fakturoid)/start$#',
        'POST #^/api/admin/imports/ai-extract-pdf$#',
        'POST #^/api/admin/imports/[0-9]+/cancel$#',
        'DELETE #^/api/admin/imports/[0-9]+$#',
        // Stav AI integrace (bez tajemství — configured/model/počet extrakcí) potřebuje
        // účetní na stránce Import, aby věděl, zda se PDF bez ISDOC zpracuje přes AI.
        'GET #^/api/admin/imports/anthropic/credentials$#',
    ];

    /**
     * Endpointy povolené i pro 'readonly' (GET data + self-service).
     * Pokud match, povoleno všem rolím (tj. i readonly).
     *
     * POZOR: dříve zde bylo blanket `'GET *'`, které pouštělo KAŽDÝ GET pro všechny
     * role — čtecí autorizace tak stála výhradně na vlastním guardu v Action vrstvě.
     * Zúženo na explicitní allowlist datových/exportních skupin, aby `/api/admin/*`
     * (mimo export carve-outy) a citlivá nastavení (signing/pdf-signing/email-branding/
     * bank-email-notices) propadla do admin-only fallbacku → middleware blokuje
     * non-admin GET i kdyby Action zapomněl vlastní kontrolu (defense-in-depth).
     * Allowlist je záměrně velkorysý na byznys data (readonly = čtení + export všeho
     * krom administrace); jemnější admin/accountant/readonly rozlišení uvnitř settings
     * řeší Action.
     */
    private const READONLY_RULES = [
        // Self-service / connection test (zbytek je v PUBLIC_OR_SELF)
        'GET #^/api/auth/(me|api-me|tokens)(/|$)#',
        'GET #^/api/auth/totp/status$#',
        '* #^/api/auth/webauthn/credentials/[0-9]+$#',
        // Byznys data
        'GET #^/api/clients(/|$)#',
        'GET #^/api/projects(/|$)#',
        'GET #^/api/invoices(/|$)#',
        'GET #^/api/purchase-invoices(/|$)#',
        'GET #^/api/recurring(/|$)#',
        'GET #^/api/bank-statements(/|$)#',
        'GET #^/api/bank-transactions(/|$)#',
        'GET #^/api/documents(/|$)#',
        'GET #^/api/document-folders(/|$)#',
        'GET #^/api/logbook(/|$)#',
        'GET #^/api/suppliers(/|$)#',
        'GET #^/api/search$#',
        // Dashboardy / CRM / reporty / daňový optimalizátor (čtení)
        'GET #^/api/dashboard(/|$)#',
        'GET #^/api/crm(/|$)#',
        'GET #^/api/reports(/|$)#',
        'GET #^/api/tax(/|$)#',
        // Číselníky
        'GET #^/api/codebooks(/|$)#',
        'GET #^/api/expense-categories(/|$)#',
        'GET #^/api/revenue-categories(/|$)#',
        'GET #^/api/vat-classifications(/|$)#',
        // Nastavení — jen čtení supplier + číselníkové sekce; signing/pdf-signing/
        // email-branding/bank-email-notices NEdáváme readonly (admin/accountant only).
        'GET #^/api/settings/supplier$#',
        'GET #^/api/settings/currencies(/|$)#',
        'GET #^/api/settings/vat-rates(/|$)#',
        'GET #^/api/settings/units(/|$)#',
        'GET #^/api/settings/countries(/|$)#',
        // Admin endpointy typu „export = čtení" (povolené i nižším rolím)
        'GET #^/api/admin/export$#',
        'GET #^/api/admin/invoices-zip$#',
        // Měsíční export = čtení (sbalí existující doklady do ZIP), jen kvůli
        // délce renderování běží jako background job — start/cancel/smazání jobu
        // jsou operační stav exportu, ne mutace business dat. MonthlyExportAction
        // má vlastní guard admin/accountant/readonly („export = čtení").
        'POST #^/api/reports/monthly-export/start$#',
        'POST #^/api/reports/monthly-export/jobs/[0-9]+/cancel$#',
        'DELETE #^/api/reports/monthly-export/jobs/[0-9]+$#',
    ];

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly PermissionPolicy $permissions,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        // OPTIONS / HEAD pouštíme dál (CORS preflight, monitoring)
        if ($method === 'OPTIONS' || $method === 'HEAD') {
            return $handler->handle($request);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $role = (string) ($user['role'] ?? '');
        $platformRole = (string) ($user['platform_role'] ?? $role);
        $user['platform_role'] = $platformRole;
        $request = $request->withAttribute(AuthMiddleware::ATTR_USER, $user);

        // Per-supplier role override (user_suppliers.role) — efektivní role pro
        // aktuální firmu. RoleMiddleware běží PŘED SupplierScope, proto supplier
        // resolvuje sdílený SupplierAccessResolver (memoizováno, SupplierScope ho
        // znovu nepočítá). Efektivní legacy roli propíšeme do ATTR_USER, aby ji
        // viděly i Action-level guardy a /api/auth/me — FE si z něj řídí
        // auth.canWrite. Původní platform role a přesná tenantová role zůstávají
        // vedle ní jako `platform_role` a `supplier_role`. Počítá se i na
        // self-service cestách (proto před PUBLIC_OR_SELF větví).
        // denied requesty neřešíme — SupplierScopeMiddleware je stejně ukončí 403.
        //
        // BEZPEČNOST: resolver vrací override=NULL pro globální adminy (ti si roli
        // nemění). Pro non-adminy navíc tvrdě zastropujeme override na 'accountant' —
        // per-supplier přiřazení NIKDY nesmí povýšit uživatele na globálního admina
        // (admin endpointy /api/admin/* nejsou supplier-scoped; jinak by šlo přes
        // override eskalovat na celoinstančního admina). 'admin' je i tak už mimo
        // povolené tenantové role (migrace 0148 + 0151); pojistka proti stray řádku.
        if ($role !== '') {
            $access = $this->supplierAccess->resolve($request);
            if (!$access->denied && $access->roleOverride !== null) {
                $supplierRole = $access->roleOverride;
                // supplier_owner dostává účetní oprávnění stávajícího jádra,
                // ale nikdy globální admin fallback pro /api/admin/*. Jeho
                // rozšířená tenantová oprávnění doplní centralizovaná policy.
                $override = in_array($supplierRole, ['admin', 'supplier_owner'], true)
                    ? 'accountant'
                    : $supplierRole;
                $user['supplier_role'] = $supplierRole;
                if ($override !== $role) {
                    $role = $override;
                    $user['role'] = $role;
                }
                $request = $request->withAttribute(AuthMiddleware::ATTR_USER, $user);
            }
        }

        // Self-service / public — Auth už dovnitř pustí jen oprávněné, role nás nezajímá
        if (in_array($path, self::PUBLIC_OR_SELF, true)
            || str_starts_with($path, '/api/public/')
        ) {
            return $handler->handle($request);
        }

        // Bez role = bez přístupu (Auth měl už 401, ale defensive)
        if ($role === '') {
            $response = $this->responseFactory->createResponse(401);
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        // Citlivé supplier-scoped operace mají vlastní permission význam. Tato
        // větev je úmyslně před legacy admin/accountant fallbackem: supplier_owner
        // dostane tenantové oprávnění, ale žádné platformní admin endpointy.
        $requiredPermission = $this->requiredPermission($method, $path);
        if ($requiredPermission !== null) {
            if ($this->permissions->allows($request, $requiredPermission)) {
                return $handler->handle($request);
            }
            $response = $this->responseFactory->createResponse(403);
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        // admin smí všechno
        if ($role === 'admin') {
            return $handler->handle($request);
        }

        // accountant: matchuj ACCOUNTANT_RULES + READONLY_RULES
        if ($role === 'accountant') {
            if ($this->matchesAny($method, $path, self::ACCOUNTANT_RULES)) {
                return $handler->handle($request);
            }
            if ($this->matchesAny($method, $path, self::READONLY_RULES)) {
                return $handler->handle($request);
            }
        }

        // readonly: jen READONLY_RULES
        if ($role === 'readonly') {
            if ($this->matchesAny($method, $path, self::READONLY_RULES)) {
                return $handler->handle($request);
            }
        }

        // Cokoliv jiného (např. admin endpointy pro non-admin role) → 403
        $response = $this->responseFactory->createResponse(403);
        return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    /**
     * @param list<string> $rules
     */
    private function matchesAny(string $method, string $path, array $rules): bool
    {
        foreach ($rules as $rule) {
            [$ruleMethod, $rulePattern] = explode(' ', $rule, 2);
            if ($ruleMethod !== '*' && $ruleMethod !== $method) continue;
            if ($rulePattern === '*') return true;
            if (preg_match($rulePattern, $path) === 1) return true;
        }
        return false;
    }

    private function requiredPermission(string $method, string $path): ?Permission
    {
        if ($method !== 'GET' && str_starts_with($path, '/api/price-list-items')) {
            return Permission::PriceListManage;
        }
        if ($method === 'PUT' && in_array($path, [
            '/api/settings/supplier',
            '/api/settings/supplier/invoice-counter',
        ], true)) {
            return Permission::SupplierSettingsManage;
        }
        if ($path === '/api/settings/supplier/members'
            || str_starts_with($path, '/api/settings/supplier/members/')
        ) {
            return Permission::SupplierMembersManage;
        }
        if (str_starts_with($path, '/api/settings/branding-profiles')
            || str_starts_with($path, '/api/settings/email-branding')
            || in_array($path, [
                '/api/settings/supplier/logo',
            ], true)
        ) {
            return Permission::SupplierBrandingManage;
        }
        return null;
    }
}
