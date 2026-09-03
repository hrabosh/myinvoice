<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Deployment;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use PHPUnit\Framework\TestCase;

final class DeploymentCapabilitiesTest extends TestCase
{
    public function testStandaloneIsBackwardCompatibleByDefault(): void
    {
        $capabilities = new DeploymentCapabilities(new Config([]));

        self::assertTrue($capabilities->isStandalone());
        self::assertTrue($capabilities->allowsFirstRunSetup());
        self::assertTrue($capabilities->allowsLocalPasswordLogin());
        self::assertTrue($capabilities->allowsSelfUpdate());
        self::assertTrue($capabilities->allowsMyuctoUpgrade());
        self::assertNull($capabilities->publicPayload()['returnUrl']);
        self::assertSame([], array_filter(
            $capabilities->modules(),
            static fn (bool $enabled): bool => !$enabled,
        ));
    }

    public function testManagedModePublishesRestrictedModulesAndTrustedReturnUrl(): void
    {
        $capabilities = new DeploymentCapabilities(new Config([
            'deployment' => [
                'mode' => 'revizior_managed',
                'public_name' => 'ReviziOR Fakturace',
                'revizior' => [
                    'app_url' => 'https://app.revizior.cz/fakturace/',
                    'allowed_return_hosts' => ['app.revizior.cz'],
                ],
            ],
        ]));

        $payload = $capabilities->publicPayload();
        self::assertSame('revizior_managed', $payload['deploymentMode']);
        self::assertSame('ReviziOR Fakturace', $payload['productName']);
        self::assertSame('https://app.revizior.cz/fakturace', $payload['returnUrl']);
        self::assertTrue($payload['modules']['salesInvoices']);
        self::assertTrue($payload['modules']['documents']);
        self::assertFalse($payload['modules']['purchaseInvoices']);
        self::assertFalse($payload['modules']['tax']);
        self::assertFalse($payload['modules']['selfUpdate']);
    }

    public function testUnknownModeFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeploymentCapabilities(new Config(['deployment' => ['mode' => 'typo']]));
    }

    public function testManagedModeRequiresHttpsReturnUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeploymentCapabilities(new Config([
            'deployment' => [
                'mode' => 'revizior_managed',
                'public_name' => 'ReviziOR Fakturace',
                'revizior' => ['app_url' => 'http://app.revizior.cz'],
            ],
        ]));
    }

    public function testReturnHostMustMatchAllowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeploymentCapabilities(new Config([
            'deployment' => [
                'mode' => 'revizior_managed',
                'public_name' => 'ReviziOR Fakturace',
                'revizior' => [
                    'app_url' => 'https://evil.example.invalid',
                    'allowed_return_hosts' => ['app.revizior.cz'],
                ],
            ],
        ]));
    }
}
