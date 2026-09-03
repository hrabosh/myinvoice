<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tenant;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sdílená resoluce supplier scope + membership (tabulka user_suppliers).
 *
 * Jediné místo, které z requestu odvozuje aktuální supplier id (token-bound
 * PAT → X-Supplier-Id header → ?supplier_id query → fallback) a zároveň
 * vynucuje membership:
 *
 *   - globální admin (users.role = 'admin'): bez omezení, vidí všechny firmy,
 *     per-supplier override se NEaplikuje (admin je důvěryhodný celoinstančně;
 *     jinak by si membershipem nebo readonly override sám sebe zamkl z /api/admin/*)
 *   - standalone uživatel BEZ membership řádků = bez omezení (dosavadní BC)
 *   - managed non-admin BEZ membership řádků = denied (tenant fail-closed)
 *   - uživatel S membership: explicitní požadavek na cizí firmu → denied
 *     (middleware vrací 403); bez explicitního požadavku fallback na nejnižší
 *     PŘIŘAZENÝ supplier (ne globální MIN — ten může patřit cizí firmě)
 *   - PAT bound na supplier_id: pokud má uživatel membership a token je bound
 *     mimo něj → denied (token nesmí obejít membership); admin/bez-membership
 *     beze změny
 *
 * Používá SupplierScopeMiddleware (403 + ATTR_CURRENT_ID) a RoleMiddleware
 * (efektivní per-supplier role). Resoluce nezávisí na ATTR_CURRENT_ID a
 * memoizuje se tady (nejvýše 1 membership dotaz + 1 existence dotaz na request).
 */
final class SupplierAccessResolver
{
    /** @var array<string, SupplierAccess> memo v rámci requestu (container = 1 instance / request) */
    private array $memo = [];

    /** @var array<int, array<int, ?string>> memo membership mapy per user */
    private array $assignmentsMemo = [];

    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly DeploymentCapabilities $capabilities,
    ) {}

    public function resolve(Request $request): SupplierAccess
    {
        $user     = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId   = (int) ($user['id'] ?? 0);
        // RoleMiddleware může 'role' přepsat na efektivní (override) roli; pro
        // membership je rozhodující jen to, jestli je uživatel globální admin —
        // a to se override změnit nemůže (ENUM v user_suppliers 'admin' nemá
        // a RoleMiddleware ho navíc stropuje na 'accountant'). Klíč memo je proto
        // stabilní i po přepisu role.
        $isAdmin = ($user['platform_role'] ?? $user['role'] ?? '') === 'admin';

        // 0. Bearer (API token) bound na konkrétní supplier — forcuj ho, ignoruj
        //    header/query (token nesmí "skočit" do jiné firmy).
        $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        if (is_array($apiToken) && ($apiToken['supplier_id'] ?? null) !== null) {
            $sid = (int) $apiToken['supplier_id'];
            $key = 'bound:' . $userId . ':' . (int) $isAdmin . ':' . $sid;
            return $this->memo[$key] ??= $this->resolveBound($userId, $isAdmin, $sid);
        }

        $requested = $this->requestedId($request);
        $key = 'u:' . $userId . ':' . (int) $isAdmin . ':' . $requested;
        return $this->memo[$key] ??= $this->doResolve($userId, $isAdmin, $requested);
    }

    /**
     * PAT bound na supplier_id. Globální admin ho dostane bez omezení; standalone
     * uživatel bez membershipu zachovává BC. Managed uživatel bez membershipu a
     * uživatel s tokenem mimo přiřazenou firmu jsou denied.
     */
    private function resolveBound(int $userId, bool $isAdmin, int $sid): SupplierAccess
    {
        if ($isAdmin) {
            return new SupplierAccess($sid, false, null);
        }
        $assignments = $this->assignmentsFor($userId);
        if ($assignments === []) {
            if ($this->capabilities->isReviziorManaged()) {
                return new SupplierAccess($sid, true, null);
            }
            return new SupplierAccess($sid, false, null);
        }
        if (!array_key_exists($sid, $assignments)) {
            return new SupplierAccess($sid, true, null);
        }
        return new SupplierAccess($sid, false, $assignments[$sid]);
    }

    private function doResolve(int $userId, bool $isAdmin, int $requested): SupplierAccess
    {
        // Globální admin = bez membership omezení a bez per-supplier override
        // (vidí všechny firmy; override by ho mohl zamknout z admin endpointů).
        if ($isAdmin) {
            return new SupplierAccess($this->resolveExisting($requested), false, null);
        }

        $assignments = $this->assignmentsFor($userId);

        // Standalone bez membership řádků = bez omezení (BC). Managed customer
        // bez explicitního membershipu musí failnout zavřeně; výjimkou je jen
        // globální platform admin obsloužený výše.
        if ($assignments === []) {
            if ($this->capabilities->isReviziorManaged()) {
                return new SupplierAccess($requested, true, null);
            }
            return new SupplierAccess($this->resolveExisting($requested), false, null);
        }

        if ($requested > 0 && $this->exists($requested)) {
            if (!array_key_exists($requested, $assignments)) {
                // Explicitní požadavek na firmu mimo membership → 403 v middleware
                return new SupplierAccess($requested, true, null);
            }
            return new SupplierAccess($requested, false, $assignments[$requested]);
        }

        // Bez headeru (nebo neexistující id) → nejnižší přiřazená firma.
        // FK v user_suppliers garantuje existenci supplier řádku.
        $ids = array_keys($assignments);
        sort($ids);
        $sid = (int) $ids[0];
        return new SupplierAccess($sid, false, $assignments[$sid]);
    }

    /** Požadované supplier id z headeru X-Supplier-Id, fallback ?supplier_id (PDF/ZIP navigace). */
    private function requestedId(Request $request): int
    {
        $headerVal = trim($request->getHeaderLine(SupplierScopeMiddleware::HEADER_NAME));
        if (ctype_digit($headerVal)) {
            return (int) $headerVal;
        }
        $q    = $request->getQueryParams();
        $qVal = isset($q['supplier_id']) ? trim((string) $q['supplier_id']) : '';
        return ctype_digit($qVal) ? (int) $qVal : 0;
    }

    /** @return array<int, ?string> */
    private function assignmentsFor(int $userId): array
    {
        if ($userId <= 0) return [];
        return $this->assignmentsMemo[$userId] ??= $this->memberships->assignmentsForUser($userId);
    }

    private function exists(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ? LIMIT 1');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Původní resoluce (před membershipem): $requested pokud existuje, jinak
     * MIN(id), jinak 0 (před setup).
     */
    private function resolveExisting(int $requested): int
    {
        if ($requested > 0 && $this->exists($requested)) {
            return $requested;
        }
        return (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
    }
}
