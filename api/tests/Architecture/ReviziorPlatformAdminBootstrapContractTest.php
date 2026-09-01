<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorPlatformAdminBootstrapContractTest extends TestCase
{
    public function testBootstrapIsManagedOnlyMfaProtectedAndNeverAcceptsPasswordArgument(): void
    {
        $script = $this->contents('api/bin/revizior-bootstrap-platform-admin.php');

        self::assertStringContainsString('isReviziorManaged()', $script);
        self::assertStringContainsString('isRequired()', $script);
        self::assertStringContainsString("'SELECT id, email, role, is_active FROM users ORDER BY id FOR UPDATE'", $script);
        self::assertStringContainsString("'platform_admin.bootstrap_created'", $script);
        self::assertStringContainsString("'supplier_memberships' => 0", $script);
        self::assertStringNotContainsString("'password' =>", $script);
        self::assertStringNotContainsString("'password:'", $script);
    }

    public function testLinuxAndWindowsWrappersCallTheSameCli(): void
    {
        foreach ([
            'cmd/revizior-bootstrap-platform-admin.sh',
            'cmd/revizior-bootstrap-platform-admin.ps1',
        ] as $path) {
            self::assertStringContainsString(
                'api/bin/revizior-bootstrap-platform-admin.php',
                $this->contents($path),
                $path,
            );
        }
    }

    private function contents(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents, $relativePath);
        return $contents;
    }
}
