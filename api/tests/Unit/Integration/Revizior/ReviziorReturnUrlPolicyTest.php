<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Integration\Revizior\ReviziorReturnUrlPolicy;
use MyInvoice\Service\Revizior\Security\ReviziorSsoException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviziorReturnUrlPolicyTest extends TestCase
{
    public function testConfiguredOriginIsAccepted(): void
    {
        $policy = $this->policy();
        self::assertSame(
            'https://app.revizior.cz/fakturace/60000000-0000-4000-8000-000000000001',
            $policy->assertAllowed('https://app.revizior.cz/fakturace/60000000-0000-4000-8000-000000000001'),
        );
    }

    /** @return iterable<string,array{string}> */
    public static function rejected(): iterable
    {
        yield 'http downgrade on allowed host' => ['http://app.revizior.cz/faktury'];
        yield 'look-alike suffix' => ['https://app.revizior.cz.evil.example/faktury'];
        yield 'look-alike prefix' => ['https://evil-app.revizior.cz/faktury'];
        yield 'other port' => ['https://app.revizior.cz:8443/faktury'];
        yield 'credentials' => ['https://user:pass@app.revizior.cz/faktury'];
        yield 'protocol relative' => ['//app.revizior.cz/faktury'];
        yield 'relative path' => ['/faktury'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'crlf injection' => ["https://app.revizior.cz/faktury\r\nSet-Cookie: a=b"];
        yield 'loopback without opt-in' => ['http://localhost:8090/faktury'];
    }

    #[DataProvider('rejected')]
    public function testUnsafeReturnUrlsAreRejected(string $returnTo): void
    {
        $this->expectException(ReviziorSsoException::class);
        $this->policy()->assertAllowed($returnTo);
    }

    /** Vývojová výjimka potřebuje flag, neprodukční prostředí i loopback host současně. */
    public function testInsecureLoopbackNeedsFlagNonProductionEnvAndLoopbackHost(): void
    {
        $dev = $this->policy(['allow_insecure_return' => true, 'insecure_return_ports' => [8090], 'env' => 'development']);
        self::assertSame('http://localhost:8090/faktury', $dev->assertAllowed('http://localhost:8090/faktury'));

        $production = $this->policy(['allow_insecure_return' => true, 'insecure_return_ports' => [8090], 'env' => 'production']);
        $this->expectException(ReviziorSsoException::class);
        $production->assertAllowed('http://localhost:8090/faktury');
    }

    public function testInsecureFlagDoesNotOpenNonLoopbackHosts(): void
    {
        $policy = $this->policy(['allow_insecure_return' => true, 'insecure_return_ports' => [8090], 'env' => 'development']);
        $this->expectException(ReviziorSsoException::class);
        $policy->assertAllowed('http://app.revizior.cz:8090/faktury');
    }

    /** @param array<string,mixed> $overrides */
    private function policy(array $overrides = []): ReviziorReturnUrlPolicy
    {
        return new ReviziorReturnUrlPolicy(new Config([
            'app' => ['env' => $overrides['env'] ?? 'production'],
            'deployment' => [
                'revizior' => [
                    'app_url' => 'https://app.revizior.cz/fakturace',
                    'allowed_return_hosts' => ['app.revizior.cz', 'localhost'],
                    'allow_insecure_return' => $overrides['allow_insecure_return'] ?? false,
                    'insecure_return_ports' => $overrides['insecure_return_ports'] ?? [],
                ],
            ],
        ]));
    }
}
