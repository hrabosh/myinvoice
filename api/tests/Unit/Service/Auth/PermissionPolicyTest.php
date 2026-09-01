<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Service\Auth\Permission;
use MyInvoice\Service\Auth\PermissionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PermissionPolicyTest extends TestCase
{
    /** @return iterable<string, array{array<string, string>, Permission, bool}> */
    public static function roleMatrix(): iterable
    {
        yield 'owner manages supplier settings' => [
            ['role' => 'accountant', 'platform_role' => 'readonly', 'supplier_role' => 'supplier_owner'],
            Permission::SupplierSettingsManage,
            true,
        ];
        yield 'owner manages price list' => [
            ['role' => 'accountant', 'platform_role' => 'readonly', 'supplier_role' => 'supplier_owner'],
            Permission::PriceListManage,
            true,
        ];
        yield 'owner cannot manage platform users' => [
            ['role' => 'accountant', 'platform_role' => 'readonly', 'supplier_role' => 'supplier_owner'],
            Permission::PlatformUsersManage,
            false,
        ];
        yield 'accountant writes invoices' => [
            ['role' => 'accountant', 'platform_role' => 'accountant'],
            Permission::InvoiceWrite,
            true,
        ];
        yield 'accountant cannot manage branding' => [
            ['role' => 'accountant', 'platform_role' => 'accountant'],
            Permission::SupplierBrandingManage,
            false,
        ];
        yield 'readonly reads invoices' => [
            ['role' => 'readonly', 'platform_role' => 'readonly'],
            Permission::InvoiceRead,
            true,
        ];
        yield 'readonly cannot write payments' => [
            ['role' => 'readonly', 'platform_role' => 'readonly'],
            Permission::PaymentWrite,
            false,
        ];
        yield 'platform admin keeps break-glass tenant access' => [
            ['role' => 'admin', 'platform_role' => 'admin'],
            Permission::SupplierMembersManage,
            true,
        ];
        yield 'tenant role cannot elevate platform permission' => [
            ['role' => 'accountant', 'platform_role' => 'readonly', 'supplier_role' => 'admin'],
            Permission::PlatformSettingsManage,
            false,
        ];
    }

    /** @param array<string, string> $user */
    #[DataProvider('roleMatrix')]
    public function testRoleMatrix(array $user, Permission $permission, bool $expected): void
    {
        $actual = in_array($permission->value, (new PermissionPolicy())->permissionsForUser($user), true);
        self::assertSame($expected, $actual);
    }

    public function testPermissionPayloadContainsNoDuplicates(): void
    {
        $permissions = (new PermissionPolicy())->permissionsForUser([
            'role' => 'accountant',
            'platform_role' => 'readonly',
            'supplier_role' => 'supplier_owner',
        ]);

        self::assertSame($permissions, array_values(array_unique($permissions)));
        self::assertContains(Permission::SupplierMembersManage->value, $permissions);
    }
}
