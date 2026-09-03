<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\Permission;
use MyInvoice\Service\Auth\PermissionPolicy;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SupplierMembersAction
{
    private const ROLES = ['supplier_owner', 'accountant', 'readonly'];

    public function __construct(
        private readonly UserSupplierRepository $memberships,
        private readonly PermissionPolicy $permissions,
        private readonly SessionManager $sessions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        return Json::ok($response, $this->memberships->listForSupplier($this->supplierId($request)));
    }

    /** @param array<string,string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $targetUserId = (int) ($args['userId'] ?? 0);
        if (($error = $this->guardTarget($request, $response, $targetUserId)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        $role = (string) ($body['role'] ?? '');
        if (!in_array($role, self::ROLES, true)) {
            return Json::error($response, 'validation_failed', 'Neplatná tenantová role.', 400);
        }

        $supplierId = $this->supplierId($request);
        try {
            $updated = $this->memberships->updateRoleForSupplier($supplierId, $targetUserId, $role);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'last_supplier_owner') return $this->lastOwner($response);
            throw $e;
        }
        if (!$updated) return Json::error($response, 'not_found', 'Člen firmy nenalezen.', 404);
        $revoked = $this->sessions->destroyAllForUser($targetUserId);
        $this->log($request, 'supplier.member_role_updated', $targetUserId, [
            'supplier_id' => $supplierId,
            'role' => $role,
            'revoked_sessions' => $revoked,
        ]);
        return Json::ok($response, [
            'members' => $this->memberships->listForSupplier($supplierId),
            'revoked_sessions' => $revoked,
        ]);
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $targetUserId = (int) ($args['userId'] ?? 0);
        if (($error = $this->guardTarget($request, $response, $targetUserId)) !== null) return $error;
        $supplierId = $this->supplierId($request);
        try {
            $deleted = $this->memberships->removeForSupplier($supplierId, $targetUserId);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'last_supplier_owner') return $this->lastOwner($response);
            throw $e;
        }
        if (!$deleted) return Json::error($response, 'not_found', 'Člen firmy nenalezen.', 404);
        $revoked = $this->sessions->destroyAllForUser($targetUserId);
        $this->log($request, 'supplier.member_removed', $targetUserId, [
            'supplier_id' => $supplierId,
            'revoked_sessions' => $revoked,
        ]);
        return Json::ok($response, ['deleted' => true, 'revoked_sessions' => $revoked]);
    }

    private function guard(Request $request, Response $response): ?Response
    {
        if (!$this->permissions->allows($request, Permission::SupplierMembersManage)) {
            return Json::error($response, 'forbidden', 'Pro správu členů firmy nemáš oprávnění.', 403);
        }
        if ($this->supplierId($request) <= 0) {
            return Json::error($response, 'no_supplier', 'Není zvolena firma.', 400);
        }
        return null;
    }

    private function guardTarget(Request $request, Response $response, int $targetUserId): ?Response
    {
        if ($targetUserId <= 0) return Json::error($response, 'validation_failed', 'Neplatné userId.', 400);
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($actor['id'] ?? 0) === $targetUserId) {
            return Json::error($response, 'self_membership_change_forbidden', 'Vlastní členství nelze změnit.', 409);
        }
        return null;
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function lastOwner(Response $response): Response
    {
        return Json::error($response, 'last_supplier_owner', 'Posledního aktivního vlastníka firmy nelze odebrat ani změnit jeho roli.', 409);
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, int $userId, array $payload): void
    {
        $actor = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            $action,
            (int) ($actor['id'] ?? 0),
            'user',
            $userId,
            $payload,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );
    }
}
