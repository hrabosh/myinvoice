<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Revizior\Security;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Revizior\Security\ReviziorServiceAuthException;
use MyInvoice\Service\Revizior\Security\ReviziorServiceTokenVerifier;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class ReviziorServiceTokenVerifierTest extends TestCase
{
    private const NOW = 1788278400;
    private const ISSUER = 'https://app.revizior.cz';
    private const AUDIENCE = 'https://fakturace.revizior.cz/api/integrations/revizior/v1';
    private const KEY_ID = 'revizior-service-2026-01';
    private const REQUEST_ID = '10000000-0000-4000-8000-000000000001';
    private const JTI = '20000000-0000-4000-8000-000000000001';

    private OpenSSLAsymmetricKey $privateKey;
    private string $publicKeyPath;
    private ReviziorServiceTokenVerifier $verifier;

    protected function setUp(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $this->privateKey = $key;
        $this->publicKeyPath = tempnam(sys_get_temp_dir(), 'revizior-public-');
        self::assertIsString($this->publicKeyPath);
        file_put_contents($this->publicKeyPath, $details['key']);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn((new DateTimeImmutable())->setTimestamp(self::NOW));
        $this->verifier = new ReviziorServiceTokenVerifier(new Config([
            'deployment' => ['revizior' => ['service_auth' => [
                'issuer' => self::ISSUER,
                'audience' => self::AUDIENCE,
                'key_id' => self::KEY_ID,
                'public_key_path' => $this->publicKeyPath,
                'clock_skew_seconds' => 5,
            ]]],
        ]), $clock);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicKeyPath);
    }

    public function testValidRs256AssertionReturnsNarrowServiceIdentity(): void
    {
        $identity = $this->verifier->verify($this->token(), self::REQUEST_ID);

        self::assertSame(self::ISSUER, $identity->issuer);
        self::assertSame('platform', $identity->subject);
        self::assertSame(self::JTI, $identity->jti);
        self::assertSame(['capabilities:read'], $identity->scopes);
        self::assertSame(self::REQUEST_ID, $identity->requestId);
    }

    public function testWrongAudienceAndRequestIdAreRejected(): void
    {
        foreach ([
            $this->token(['aud' => 'https://attacker.example']),
            $this->token(['request_id' => '30000000-0000-4000-8000-000000000001']),
        ] as $token) {
            try {
                $this->verifier->verify($token, self::REQUEST_ID);
                self::fail('Assertion s jiným audience/request ID nesmí projít.');
            } catch (ReviziorServiceAuthException $e) {
                self::assertSame('service_token_invalid', $e->errorCode);
            }
        }
    }

    public function testExpiredAndOverlongAssertionsAreRejected(): void
    {
        try {
            $this->verifier->verify($this->token([
                'iat' => self::NOW - 120,
                'nbf' => self::NOW - 125,
                'exp' => self::NOW - 60,
            ]), self::REQUEST_ID);
            self::fail('Expirovaný assertion nesmí projít.');
        } catch (ReviziorServiceAuthException $e) {
            self::assertSame('service_token_expired', $e->errorCode);
        }

        $this->expectException(ReviziorServiceAuthException::class);
        $this->verifier->verify($this->token(['exp' => self::NOW + 61]), self::REQUEST_ID);
    }

    public function testAlgorithmAndKidAreBoundToConfiguration(): void
    {
        foreach ([
            $this->token([], ['alg' => 'HS256']),
            $this->token([], ['kid' => 'unknown']),
        ] as $token) {
            try {
                $this->verifier->verify($token, self::REQUEST_ID);
                self::fail('Jiný algoritmus ani kid nesmí projít.');
            } catch (ReviziorServiceAuthException $e) {
                self::assertSame('service_token_invalid', $e->errorCode);
            }
        }
    }

    public function testMalformedConfiguredPublicKeyIsUnavailableNotClientError(): void
    {
        file_put_contents($this->publicKeyPath, 'not a public key');

        try {
            $this->verifier->verify($this->token(), self::REQUEST_ID);
            self::fail('Poškozený serverový klíč nesmí vypadat jako chyba klienta.');
        } catch (ReviziorServiceAuthException $e) {
            self::assertSame('provider_temporarily_unavailable', $e->errorCode);
            self::assertSame(503, $e->httpStatus);
            self::assertTrue($e->retryable);
        }
    }

    /**
     * @param array<string,mixed> $claimOverrides
     * @param array<string,mixed> $headerOverrides
     */
    private function token(array $claimOverrides = [], array $headerOverrides = []): string
    {
        $header = array_replace([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => self::KEY_ID,
        ], $headerOverrides);
        $claims = array_replace([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'platform',
            'jti' => self::JTI,
            'iat' => self::NOW,
            'nbf' => self::NOW - 5,
            'exp' => self::NOW + 60,
            'scope' => ['capabilities:read'],
            'request_id' => self::REQUEST_ID,
        ], $claimOverrides);

        $input = $this->encode($header) . '.' . $this->encode($claims);
        $signature = '';
        self::assertTrue(openssl_sign($input, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));
        return $input . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return rtrim(strtr(base64_encode(json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )), '+/', '-_'), '=');
    }
}
