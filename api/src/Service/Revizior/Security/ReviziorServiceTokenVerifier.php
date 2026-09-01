<?php

declare(strict_types=1);

namespace MyInvoice\Service\Revizior\Security;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

final class ReviziorServiceTokenVerifier
{
    public const MAX_TOKEN_BYTES = 8192;
    public const MAX_TTL_SECONDS = 60;
    public const MAX_CLOCK_SKEW_SECONDS = 30;

    private ?JWK $publicKey = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {}

    public function isConfigured(): bool
    {
        return $this->issuer() !== ''
            && $this->audience() !== ''
            && $this->keyId() !== ''
            && is_readable($this->publicKeyPath());
    }

    public function verify(string $token, string $requestId): ReviziorServiceIdentity
    {
        if (!$this->isConfigured()) {
            throw ReviziorServiceAuthException::unavailable();
        }
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            throw ReviziorServiceAuthException::unauthorized();
        }
        if (!Uuid::isValid($requestId)) {
            throw ReviziorServiceAuthException::unauthorized();
        }

        try {
            $jws = (new CompactSerializer())->unserialize($token);
            if ($jws->countSignatures() !== 1) {
                throw ReviziorServiceAuthException::unauthorized();
            }
            $header = $jws->getSignature(0)->getProtectedHeader();
            if (($header['alg'] ?? null) !== 'RS256'
                || ($header['kid'] ?? null) !== $this->keyId()
                || ($header['typ'] ?? null) !== 'JWT'
            ) {
                throw ReviziorServiceAuthException::unauthorized();
            }

            $verifier = new JWSVerifier(new AlgorithmManager([new RS256()]));
            if (!$verifier->verifyWithKey($jws, $this->publicKey(), 0)) {
                throw ReviziorServiceAuthException::unauthorized();
            }

            $claims = json_decode((string) $jws->getPayload(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($claims)) {
                throw ReviziorServiceAuthException::unauthorized();
            }
            return $this->validatedIdentity($claims, $requestId);
        } catch (ReviziorServiceAuthException $e) {
            throw $e;
        } catch (Throwable) {
            throw ReviziorServiceAuthException::unauthorized();
        }
    }

    /** @param array<string,mixed> $claims */
    private function validatedIdentity(array $claims, string $requestId): ReviziorServiceIdentity
    {
        $issuer = $this->stringClaim($claims, 'iss');
        $audience = $this->stringClaim($claims, 'aud');
        $subject = $this->stringClaim($claims, 'sub');
        $jti = $this->stringClaim($claims, 'jti');
        $tokenRequestId = $this->stringClaim($claims, 'request_id');
        $issuedAt = $this->intClaim($claims, 'iat');
        $notBefore = $this->intClaim($claims, 'nbf');
        $expiresAt = $this->intClaim($claims, 'exp');
        $scopes = $claims['scope'] ?? null;

        if ($issuer !== $this->issuer() || $audience !== $this->audience()) {
            throw ReviziorServiceAuthException::unauthorized();
        }
        if (!Uuid::isValid($jti) || !hash_equals($tokenRequestId, $requestId)) {
            throw ReviziorServiceAuthException::unauthorized();
        }
        if (!is_array($scopes) || $scopes === []) {
            throw ReviziorServiceAuthException::unauthorized();
        }
        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '' || in_array($scope, $normalizedScopes, true)) {
                throw ReviziorServiceAuthException::unauthorized();
            }
            $normalizedScopes[] = $scope;
        }

        $now = $this->clock->now()->getTimestamp();
        $skew = min(self::MAX_CLOCK_SKEW_SECONDS, max(0, (int) $this->config->get(
            'deployment.revizior.service_auth.clock_skew_seconds',
            5,
        )));
        if ($expiresAt - $issuedAt < 1 || $expiresAt - $issuedAt > self::MAX_TTL_SECONDS) {
            throw ReviziorServiceAuthException::unauthorized();
        }
        if ($issuedAt > $now + $skew || $notBefore > $now + $skew) {
            throw ReviziorServiceAuthException::unauthorized();
        }
        if ($expiresAt < $now - $skew) {
            throw ReviziorServiceAuthException::unauthorized('service_token_expired');
        }

        return new ReviziorServiceIdentity(
            $issuer,
            $subject,
            strtolower($jti),
            $expiresAt,
            $normalizedScopes,
            $tokenRequestId,
        );
    }

    /** @param array<string,mixed> $claims */
    private function stringClaim(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw ReviziorServiceAuthException::unauthorized();
        }
        return $value;
    }

    /** @param array<string,mixed> $claims */
    private function intClaim(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;
        if (!is_int($value)) {
            throw ReviziorServiceAuthException::unauthorized();
        }
        return $value;
    }

    private function issuer(): string
    {
        return trim((string) $this->config->get('deployment.revizior.service_auth.issuer', ''));
    }

    private function audience(): string
    {
        return trim((string) $this->config->get('deployment.revizior.service_auth.audience', ''));
    }

    private function keyId(): string
    {
        return trim((string) $this->config->get('deployment.revizior.service_auth.key_id', ''));
    }

    private function publicKeyPath(): string
    {
        return trim((string) $this->config->get('deployment.revizior.service_auth.public_key_path', ''));
    }

    private function publicKey(): JWK
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }
        try {
            return $this->publicKey = JWKFactory::createFromKeyFile($this->publicKeyPath(), null, [
                'alg' => 'RS256',
                'kid' => $this->keyId(),
                'use' => 'sig',
            ]);
        } catch (Throwable) {
            throw ReviziorServiceAuthException::unavailable();
        }
    }
}
