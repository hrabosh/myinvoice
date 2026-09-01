<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorSupplierOwnerMigrationTest extends TestCase
{
    public function testMigrationAddsTenantOwnerWithoutGlobalAdminRole(): void
    {
        $path = dirname(__DIR__, 3) . '/db/migrations/0151_revizior_supplier_owner.sql';
        $sql = file_get_contents($path);
        self::assertNotFalse($sql);

        self::assertStringContainsString('MODIFY COLUMN IF EXISTS role', $sql);
        self::assertStringContainsString("ENUM('supplier_owner','accountant','readonly')", $sql);
        self::assertStringNotContainsString('ALTER TABLE users', $sql);
        self::assertStringNotContainsString("ENUM('admin','supplier_owner'", $sql);
    }
}
