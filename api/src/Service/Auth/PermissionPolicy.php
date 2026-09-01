<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Middleware\AuthMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PermissionPolicy
{
    /** @return list<string> */
    public function permissions(Request $request): array
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return $this->permissionsForUser($user);
    }

    public function allows(Request $request, Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions($request), true);
    }

    /**
     * Globální admin si zatím zachovává standalone kompatibilitu a funguje jako
     * platformní break-glass i nad tenantem. Supplier membership však nikdy
     * nemůže platformní oprávnění přidat.
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    public function permissionsForUser(array $user): array
    {
        $platformRole = (string) ($user['platform_role'] ?? $user['role'] ?? '');
        if ($platformRole === 'admin') {
            return array_map(
                static fn (Permission $permission): string => $permission->value,
                Permission::cases(),
            );
        }

        $supplierRole = (string) ($user['supplier_role'] ?? $user['role'] ?? '');
        $permissions = match ($supplierRole) {
            'supplier_owner' => [
                Permission::InvoiceRead,
                Permission::InvoiceWrite,
                Permission::InvoiceIssue,
                Permission::InvoiceCancel,
                Permission::PaymentWrite,
                Permission::ClientRead,
                Permission::ClientWrite,
                Permission::ProjectWrite,
                Permission::PriceListManage,
                Permission::SupplierSettingsManage,
                Permission::SupplierMembersManage,
                Permission::SupplierBrandingManage,
                Permission::SupplierExportsRead,
            ],
            'accountant' => [
                Permission::InvoiceRead,
                Permission::InvoiceWrite,
                Permission::InvoiceIssue,
                Permission::InvoiceCancel,
                Permission::PaymentWrite,
                Permission::ClientRead,
                Permission::ClientWrite,
                Permission::ProjectWrite,
                Permission::SupplierExportsRead,
            ],
            'readonly' => [
                Permission::InvoiceRead,
                Permission::ClientRead,
                Permission::SupplierExportsRead,
            ],
            default => [],
        };

        return array_map(
            static fn (Permission $permission): string => $permission->value,
            $permissions,
        );
    }
}
