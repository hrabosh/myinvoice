<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReviziorSsoContractTest extends TestCase
{
    private const PATH = '/api/auth/revizior/sso';

    public function testSsoRouteIsPublicOutsideTheServiceApiAndDescribedInBothDocuments(): void
    {
        self::assertStringContainsString("'" . self::PATH . "'", $this->read('api/src/Routes.php'));
        // Endpoint si session teprve vydává, takže nesmí být za AuthMiddleware.
        self::assertStringContainsString(self::PATH, $this->read('api/src/Middleware/AuthMiddleware.php'));
        // RoleMiddleware má vlastní allowlist; bez něj skončí požadavek na 401
        // dřív, než se ticket vůbec ověří (odhaleno prvním živým průchodem).
        self::assertStringContainsString(self::PATH, $this->read('api/src/Middleware/RoleMiddleware.php'));
        // A zároveň nesmí být pod service API prefixem (jiné pověření, jiné audience).
        self::assertStringNotContainsString('/api/integrations/revizior/v1' . self::PATH, $this->read('api/src/Routes.php'));
        foreach (['api/openapi.yaml', 'api/openapi-revizior-integration.yaml'] as $document) {
            self::assertStringContainsString(self::PATH, $this->read($document));
        }
    }

    public function testTicketVerificationUsesSeparateAudienceAndOneTimeNonce(): void
    {
        $verifier = $this->read('api/src/Service/Revizior/Security/ReviziorSsoTicketVerifier.php');
        self::assertStringContainsString("deployment.revizior.sso.audience", $verifier);
        self::assertStringContainsString("'browser_sso'", str_replace('self::PURPOSE', "'browser_sso'", $verifier));
        self::assertStringContainsString('RS256', $verifier);

        $service = $this->read('api/src/Service/Integration/Revizior/ReviziorSsoService.php');
        self::assertStringContainsString('consumeNonce(', $service);
        self::assertStringContainsString('revizior_user_links', $service);
        self::assertStringContainsString('user_suppliers', $service);
        self::assertStringContainsString('targetPolicy->resolve(', $service);
        self::assertStringContainsString('returnUrlPolicy->assertAllowed(', $service);
        // Role z ticketu se nikdy nepoužije — členství se čte z DB.
        self::assertStringNotContainsString('$claims->role', $service);

        $guard = $this->read('api/src/Service/Revizior/Security/ReviziorReplayGuard.php');
        self::assertStringContainsString("\$purpose . ':' . \$jti", $guard);
    }

    public function testActionNeverEchoesTheTicketAndAlwaysRedirects(): void
    {
        $action = $this->read('api/src/Action/Revizior/ReviziorSsoAction.php');
        self::assertStringContainsString('withStatus(303)', $action);
        self::assertStringContainsString("'Set-Cookie'", $action);
        self::assertStringContainsString("'no-store'", $action);
        // Ticket se nesmí dostat do logu ani do chybové stránky.
        self::assertStringNotContainsString("'ticket' =>", $action);
        self::assertStringNotContainsString('$ticket)', substr($action, (int) strpos($action, 'private function page')));
        self::assertStringContainsString("'error_code' => \$e->errorCode", $action);
    }

    /** Ticket je pověření v URL — access log ho pro tuhle cestu nesmí zapsat. */
    public function testWebServerRedactsTheTicketFromTheAccessLog(): void
    {
        $nginx = $this->read('docker/nginx.conf');
        self::assertStringContainsString('log_format  redacted', $nginx);
        self::assertStringContainsString('location = ' . self::PATH, $nginx);
        self::assertStringContainsString('location @api_sso', $nginx);
        self::assertStringContainsString('/dev/stdout redacted', $nginx);
    }

    public function testComposePassesSsoConfigurationToTheContainer(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.production.yml'] as $file) {
            $compose = $this->read($file);
            foreach (['MYINVOICE_REVIZIOR_SSO_AUDIENCE', 'MYINVOICE_REVIZIOR_SSO_KEY_ID', 'MYINVOICE_REVIZIOR_SSO_PUBLIC_KEY'] as $variable) {
                self::assertStringContainsString($variable, $compose, $file);
            }
        }
    }

    public function testMigrationStoresApprovedReturnUrlOnTheSession(): void
    {
        $migration = $this->read('db/migrations/0156_revizior_sso_session_return.sql');
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS revizior_return_url', $migration);
        self::assertStringNotContainsString('DROP ', strtoupper($migration));
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertNotFalse($content);
        return $content;
    }
}
