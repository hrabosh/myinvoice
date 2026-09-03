<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Revizior\Security\ReviziorSsoException;
use MyInvoice\Service\Revizior\Security\ReviziorSsoTicketVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class ReviziorSsoTicketVerifierTest extends TestCase
{
    private const ISSUER = 'https://app.revizior.cz';
    private const AUDIENCE = 'https://fakturace.revizior.cz/api/auth/revizior/sso';
    private const SERVICE_AUDIENCE = 'https://fakturace.revizior.cz/api/integrations/revizior/v1';
    private const KEY_ID = 'revizior-sso-2026-01';
    private const USER = '29000000-0000-4000-8000-000000000001';
    private const ORGANIZATION = '39000000-0000-4000-8000-000000000001';

    private static ?string $privateKeyPath = null;
    private static ?string $publicKeyPath = null;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);
        $publicPem = (string) openssl_pkey_get_details($key)['key'];
        self::$privateKeyPath = tempnam(sys_get_temp_dir(), 'sso-priv');
        self::$publicKeyPath = tempnam(sys_get_temp_dir(), 'sso-pub');
        file_put_contents((string) self::$privateKeyPath, (string) $privatePem);
        file_put_contents((string) self::$publicKeyPath, $publicPem);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$privateKeyPath, self::$publicKeyPath] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testValidTicketIsAccepted(): void
    {
        $ticket = $this->verifier()->verify($this->sign());

        self::assertSame(self::ISSUER, $ticket->issuer);
        self::assertSame(self::USER, $ticket->userUuid);
        self::assertSame(self::ORGANIZATION, $ticket->organizationUuid);
        self::assertSame('/invoices/1/edit', $ticket->target);
        self::assertSame('https://app.revizior.cz/faktury', $ticket->returnTo);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidClaims(): iterable
    {
        yield 'service audience cannot be replayed as SSO' => ['aud' => self::SERVICE_AUDIENCE];
        yield 'wrong issuer' => ['iss' => 'https://evil.example'];
        yield 'wrong purpose' => ['purpose' => 'service'];
        yield 'non uuid subject' => ['sub' => 'owner@example.invalid'];
        yield 'non uuid organization' => ['organization_id' => '12345'];
        yield 'expired' => ['iat' => 1788119000, 'nbf' => 1788119000, 'exp' => 1788119060];
        yield 'ttl too long' => ['exp' => 1788120000 + 3600];
        yield 'issued in the future' => ['iat' => 1788130000, 'nbf' => 1788130000, 'exp' => 1788130060];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('invalidClaims')]
    public function testInvalidTicketsAreRejected(mixed ...$overrides): void
    {
        /** @var array<string,mixed> $claims */
        $claims = $overrides;
        $this->expectException(ReviziorSsoException::class);
        $this->verifier()->verify($this->sign($claims));
    }

    public function testTicketSignedWithAnotherKeyIsRejected(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($other);
        openssl_pkey_export($other, $otherPem);
        $path = (string) tempnam(sys_get_temp_dir(), 'sso-other');
        file_put_contents($path, (string) $otherPem);

        try {
            $this->expectException(ReviziorSsoException::class);
            $this->verifier()->verify($this->sign([], $path));
        } finally {
            unlink($path);
        }
    }

    /** Rotace bez odstávky: po dobu překryvu platí starý i nový klíč. */
    public function testBothKeysAreAcceptedDuringRotationOverlap(): void
    {
        $rotated = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rotated);
        openssl_pkey_export($rotated, $rotatedPrivate);
        $privatePath = (string) tempnam(sys_get_temp_dir(), 'sso-rot-priv');
        $publicPath = (string) tempnam(sys_get_temp_dir(), 'sso-rot-pub');
        file_put_contents($privatePath, (string) $rotatedPrivate);
        file_put_contents($publicPath, (string) openssl_pkey_get_details($rotated)['key']);

        try {
            $verifier = $this->verifier(['revizior-sso-2026-02' => $publicPath]);

            self::assertSame(self::USER, $verifier->verify($this->sign())->userUuid, 'Starý klíč musí platit dál.');
            self::assertSame(
                self::USER,
                $verifier->verify($this->sign([], $privatePath, 'revizior-sso-2026-02'))->userUuid,
                'Nový klíč musí platit hned.',
            );

            $this->expectException(ReviziorSsoException::class);
            $verifier->verify($this->sign([], $privatePath, 'revizior-sso-2026-03'));
        } finally {
            unlink($privatePath);
            unlink($publicPath);
        }
    }

    public function testGarbageAndOversizedTicketsAreRejected(): void
    {
        foreach (['', 'not-a-jws', str_repeat('a', ReviziorSsoTicketVerifier::MAX_TICKET_BYTES + 1)] as $ticket) {
            try {
                $this->verifier()->verify($ticket);
                self::fail('expected rejection');
            } catch (ReviziorSsoException $e) {
                self::assertSame(401, $e->httpStatus);
            }
        }
    }

    /** @param array<string,mixed> $overrides */
    private function sign(array $overrides = [], ?string $privateKeyPath = null, ?string $keyId = null): string
    {
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => self::USER,
            'organization_id' => self::ORGANIZATION,
            'jti' => '10000000-0000-4000-8000-000000000001',
            'iat' => 1788120000,
            'nbf' => 1788120000,
            'exp' => 1788120060,
            'purpose' => 'browser_sso',
            'target' => '/invoices/1/edit',
            'return_to' => 'https://app.revizior.cz/faktury',
        ], $overrides);

        $key = JWKFactory::createFromKeyFile($privateKeyPath ?? (string) self::$privateKeyPath);
        $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))
            ->create()
            ->withPayload((string) json_encode($claims, JSON_THROW_ON_ERROR))
            ->addSignature($key, ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyId ?? self::KEY_ID])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /** @param array<string, string> $rotationKeys */
    private function verifier(array $rotationKeys = []): ReviziorSsoTicketVerifier
    {
        return new ReviziorSsoTicketVerifier(
            new Config([
                'deployment' => [
                    'revizior' => [
                        'service_auth' => [
                            'issuer' => self::ISSUER,
                            'audience' => self::SERVICE_AUDIENCE,
                            'key_id' => 'revizior-service-2026-01',
                            'public_key_path' => (string) self::$publicKeyPath,
                            'clock_skew_seconds' => 5,
                        ],
                        'sso' => [
                            'audience' => self::AUDIENCE,
                            'key_id' => self::KEY_ID,
                            'public_key_path' => (string) self::$publicKeyPath,
                            'public_keys' => $rotationKeys,
                        ],
                    ],
                ],
            ]),
            new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('@1788120010');
                }
            },
        );
    }
}
