<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa membershipu uživatel ↔ supplier (tabulka user_suppliers):
 *
 *   GET /api/admin/users/{id}/suppliers — [{supplier_id, name, ic, role}]
 *   PUT /api/admin/users/{id}/suppliers — replace celé sady
 *       body: {assignments: [{supplier_id, role|null}]}
 *
 * Prázdné assignments = žádné explicitní membershipy. Ve standalone režimu to
 * zachovává BC přístup ke všem firmám; managed resolver takového non-admina
 * odmítne. role NULL = zdědit globální users.role.
 */
final class UserSupplierAdminAction
{
    // 'admin' ZÁMĚRNĚ chybí — per-supplier admin by eskaloval na globálního admina
    // (endpointy /api/admin/* nejsou supplier-scoped). Globální adminy určuje users.role.
    private const ROLES = ['supplier_owner', 'accountant', 'readonly'];

    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /**
     * @param array<string,string> $args
     */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        if (!$this->userExists($id)) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        return Json::ok($response, $this->repo->listForUser($id));
    }

    /**
     * @param array<string,string> $args
     */
    public function replace(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        if (!$this->userExists($id)) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);

        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['assignments']) || !is_array($body['assignments'])) {
            return Json::error($response, 'validation_failed', "Pole 'assignments' je povinné (pole objektů {supplier_id, role|null}).", 400);
        }

        $assignments = [];
        $seen = [];
        foreach ($body['assignments'] as $a) {
            if (!is_array($a)) {
                return Json::error($response, 'validation_failed', 'Každé přiřazení musí být objekt {supplier_id, role|null}.', 400);
            }
            $sid = (int) ($a['supplier_id'] ?? 0);
            if ($sid <= 0) {
                return Json::error($response, 'validation_failed', 'Neplatné supplier_id.', 400);
            }
            if (isset($seen[$sid])) {
                return Json::error($response, 'validation_failed', "Duplicitní supplier_id $sid v assignments.", 400);
            }
            $role = $a['role'] ?? null;
            if ($role !== null && !in_array($role, self::ROLES, true)) {
                return Json::error($response, 'validation_failed', 'Neplatná role — povolené: supplier_owner, accountant, readonly nebo null (zdědit globální).', 400);
            }
            $seen[$sid] = true;
            $assignments[] = ['supplier_id' => $sid, 'role' => $role !== null ? (string) $role : null];
        }

        // Existence supplierů jedním dotazem
        if ($assignments !== []) {
            $ids = array_keys($seen);
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id FROM supplier WHERE id IN ($place)");
            $stmt->execute($ids);
            $existing = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            $missing = array_diff($ids, $existing);
            if ($missing !== []) {
                return Json::error($response, 'validation_failed', 'Neexistující supplier_id: ' . implode(', ', $missing) . '.', 400);
            }
        }

        $this->repo->replaceForUser($id, $assignments);
        $this->log($request, 'user.suppliers_updated', $id, ['assignments' => $assignments]);
        return Json::ok($response, $this->repo->listForUser($id));
    }

    private function userExists(int $id): bool
    {
        if ($id <= 0) return false;
        $stmt = $this->db->pdo()->prepare('SELECT id FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function guard(Request $request, Response $response): ?Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'user', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
