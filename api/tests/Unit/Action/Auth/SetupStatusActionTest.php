<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\SetupStatusAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasskeyService;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SetupStatusActionTest extends TestCase
{
    public function testPasswordlessLoginIsExposedOnlyWhenConfiguredAndAvailable(): void
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn(false);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::once())->method('isAvailable')->willReturn(true);
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::once())->method('isMethodAllowed')->with('passkey')->willReturn(true);
        $config = new Config([
            'auth' => ['passwordless_login' => ['enabled' => true]],
            'captcha' => ['provider' => 'none'],
        ]);
        $action = new SetupStatusAction(
            $lock,
            $config,
            $passkeys,
            $policy,
            new DeploymentCapabilities($config),
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-status'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['passwordless_login_enabled']);
    }

    public function testPasswordlessLoginDefaultsToHiddenWithoutProbingWebAuthn(): void
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn(false);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::never())->method('isMethodAllowed');
        $config = new Config(['captcha' => ['provider' => 'none']]);
        $action = new SetupStatusAction(
            $lock,
            $config,
            $passkeys,
            $policy,
            new DeploymentCapabilities($config),
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-status'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($body['passwordless_login_enabled']);
        self::assertSame('standalone', $body['deploymentMode']);
        self::assertTrue($body['modules']['selfUpdate']);
    }

    public function testManagedModeHidesLocalPasswordlessLoginAndSetup(): void
    {
        $lock = $this->createMock(FirstRunLockMiddleware::class);
        $lock->method('needsSetup')->willReturn(false);
        $passkeys = $this->createMock(PasskeyService::class);
        $passkeys->expects(self::never())->method('isAvailable');
        $policy = $this->createMock(MfaPolicyService::class);
        $policy->expects(self::never())->method('isMethodAllowed');
        $config = new Config([
            'deployment' => [
                'mode' => 'revizior_managed',
                'public_name' => 'ReviziOR Fakturace',
                'revizior' => ['app_url' => 'https://app.revizior.cz/fakturace'],
            ],
            'auth' => ['passwordless_login' => ['enabled' => true]],
            'captcha' => ['provider' => 'none'],
        ]);
        $action = new SetupStatusAction(
            $lock,
            $config,
            $passkeys,
            $policy,
            new DeploymentCapabilities($config),
        );

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/auth/setup-status'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($body['needs_setup']);
        self::assertFalse($body['passwordless_login_enabled']);
        self::assertSame('revizior_managed', $body['deploymentMode']);
        self::assertFalse($body['modules']['selfUpdate']);
    }
}
