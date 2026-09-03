<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorServiceFoundationContractTest extends TestCase
{
    public function testFoundationMigrationIsAdditiveIdempotentAndTenantConstrained(): void
    {
        $sql = $this->read('db/migrations/0152_revizior_service_foundation.sql');

        foreach ([
            'revizior_organization_links',
            'revizior_user_links',
            'revizior_idempotency_keys',
            'revizior_security_nonces',
        ] as $table) {
            self::assertStringContainsString("CREATE TABLE IF NOT EXISTS {$table}", $sql);
        }
        self::assertStringContainsString('UNIQUE KEY uq_revizior_org_uuid (organization_uuid)', $sql);
        self::assertStringContainsString('UNIQUE KEY uq_revizior_org_supplier (supplier_id)', $sql);
        self::assertStringContainsString('FOREIGN KEY (supplier_id) REFERENCES supplier(id)', $sql);
        self::assertStringContainsString('FOREIGN KEY (user_id) REFERENCES users(id)', $sql);
        self::assertStringContainsString('jti_hash', $sql);
        self::assertStringNotContainsString('DROP TABLE', strtoupper($sql));
    }

    public function testServiceNamespaceHasDedicatedAuthBeforeUserAuth(): void
    {
        $bootstrap = $this->read('api/src/Bootstrap.php');
        $routes = $this->read('api/src/Routes.php');
        $replayGuard = $this->read('api/src/Service/Revizior/Security/ReviziorReplayGuard.php');
        $composer = json_decode($this->read('api/composer.json'), true, 32, JSON_THROW_ON_ERROR);

        self::assertStringContainsString(
            "'/api/integrations/revizior/v1/capabilities'",
            $routes,
        );
        self::assertGreaterThan(
            strpos($bootstrap, 'add($container->get(AuthMiddleware::class))'),
            strpos($bootstrap, 'add($container->get(ReviziorServiceAuthMiddleware::class))'),
            'Slim middleware je LIFO: service auth musí být přidán po user AuthMiddleware.',
        );
        self::assertSame('^4.2.2', $composer['require']['web-token/jwt-framework'] ?? null);
        self::assertStringContainsString(
            'UTC_TIMESTAMP(6) - INTERVAL 30 SECOND',
            $replayGuard,
            'Replay row nesmí zmizet během povoleného clock skew okna.',
        );
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($content);
        return $content;
    }
}
