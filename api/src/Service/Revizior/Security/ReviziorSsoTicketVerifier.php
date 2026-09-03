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

/**
 * Ověření jednorázového SSO ticketu z prohlížeče (R4, §2.14 zadání).
 *
 * ## Vlastní audience a klíč
 *
 * Ticket i service assertion jsou RS256 JWS od téhož vydavatele. Kdyby sdílely
 * audience, ticket vytažený z historie prohlížeče by šel poslat na service API.
 * Audience je proto povinně jiná a `kid` může (a má) být jiný klíč; když není
 * nakonfigurovaný vlastní, použije se service klíč — ale audience se nikdy
 * nesdílí.
 *
 * ## Co se nevěří
 *
 * Role ani supplier v ticketu nejsou a být nesmí: členství se načítá z DB.
 * `target` a `return_to` jsou jen **návrh** — obojí prochází vlastní politikou.
 */
final class ReviziorSsoTicketVerifier
{
    public const MAX_TICKET_BYTES = 8192;
    public const MAX_TTL_SECONDS = 120;
    public const PURPOSE = 'browser_sso';

    /** @var array<string, JWK> načtené klíče podle `kid` */
    private array $publicKeys = [];

    public function __construct(
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {}

    public function isConfigured(): bool
    {
        return $this->issuer() !== ''
            && $this->audience() !== ''
            && $this->acceptedKeys() !== [];
    }

    public function verify(string $ticket): ReviziorSsoTicket
    {
        if (!$this->isConfigured()) {
            throw ReviziorSsoException::unavailable();
        }
        if ($ticket === '' || strlen($ticket) > self::MAX_TICKET_BYTES) {
            throw ReviziorSsoException::invalidTicket();
        }

        try {
            $jws = (new CompactSerializer())->unserialize($ticket);
            if ($jws->countSignatures() !== 1) {
                throw ReviziorSsoException::invalidTicket();
            }
            $header = $jws->getSignature(0)->getProtectedHeader();
            $keyId = $header['kid'] ?? null;
            if (($header['alg'] ?? null) !== 'RS256'
                || !is_string($keyId)
                || !array_key_exists($keyId, $this->acceptedKeys())
                || ($header['typ'] ?? null) !== 'JWT'
            ) {
                throw ReviziorSsoException::invalidTicket();
            }
            $verifier = new JWSVerifier(new AlgorithmManager([new RS256()]));
            if (!$verifier->verifyWithKey($jws, $this->publicKey($keyId), 0)) {
                throw ReviziorSsoException::invalidTicket();
            }
            $claims = json_decode((string) $jws->getPayload(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($claims)) {
                throw ReviziorSsoException::invalidTicket();
            }

            return $this->validated($claims);
        } catch (ReviziorSsoException $e) {
            throw $e;
        } catch (Throwable) {
            throw ReviziorSsoException::invalidTicket();
        }
    }

    /** @param array<string,mixed> $claims */
    private function validated(array $claims): ReviziorSsoTicket
    {
        $issuer = $this->stringClaim($claims, 'iss');
        $audience = $this->stringClaim($claims, 'aud');
        $subject = strtolower($this->stringClaim($claims, 'sub'));
        $organizationUuid = strtolower($this->stringClaim($claims, 'organization_id'));
        $jti = strtolower($this->stringClaim($claims, 'jti'));
        $purpose = $this->stringClaim($claims, 'purpose');
        $target = $this->stringClaim($claims, 'target');
        $returnTo = $this->stringClaim($claims, 'return_to');
        $issuedAt = $this->intClaim($claims, 'iat');
        $notBefore = $this->intClaim($claims, 'nbf');
        $expiresAt = $this->intClaim($claims, 'exp');

        if ($issuer !== $this->issuer() || $audience !== $this->audience()) {
            throw ReviziorSsoException::invalidTicket();
        }
        if ($purpose !== self::PURPOSE) {
            throw ReviziorSsoException::invalidTicket();
        }
        if (!Uuid::isValid($jti) || !Uuid::isValid($subject) || !Uuid::isValid($organizationUuid)) {
            throw ReviziorSsoException::invalidTicket();
        }
        if (strlen($target) > 512 || strlen($returnTo) > 2048) {
            throw ReviziorSsoException::invalidTicket();
        }

        $now = $this->clock->now()->getTimestamp();
        $skew = min(30, max(0, (int) $this->config->get('deployment.revizior.service_auth.clock_skew_seconds', 5)));
        if ($expiresAt - $issuedAt < 1 || $expiresAt - $issuedAt > self::MAX_TTL_SECONDS) {
            throw ReviziorSsoException::invalidTicket();
        }
        if ($issuedAt > $now + $skew || $notBefore > $now + $skew) {
            throw ReviziorSsoException::invalidTicket();
        }
        if ($expiresAt < $now - $skew) {
            throw ReviziorSsoException::invalidTicket('sso_ticket_expired');
        }

        return new ReviziorSsoTicket($issuer, $subject, $organizationUuid, $jti, $expiresAt, $target, $returnTo);
    }

    /** @param array<string,mixed> $claims */
    private function stringClaim(array $claims, string $name): string
    {
        $value = $claims[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw ReviziorSsoException::invalidTicket();
        }
        return $value;
    }

    /** @param array<string,mixed> $claims */
    private function intClaim(array $claims, string $name): int
    {
        $value = $claims[$name] ?? null;
        if (!is_int($value)) {
            throw ReviziorSsoException::invalidTicket();
        }
        return $value;
    }

    private function issuer(): string
    {
        return trim((string) $this->config->get('deployment.revizior.service_auth.issuer', ''));
    }

    private function audience(): string
    {
        return trim((string) $this->config->get('deployment.revizior.sso.audience', ''));
    }

    /**
     * Přijímané SSO klíče `kid => cesta k PEM`.
     *
     * Mapa kvůli rotaci bez odstávky (po dobu překryvu platí starý i nový).
     * Bez vlastního SSO klíče se použije service klíč — audience zůstává
     * oddělená, takže ticket pořád nejde poslat na service API.
     *
     * @return array<string, string>
     */
    private function acceptedKeys(): array
    {
        $keys = [];
        $keyId = trim((string) $this->config->get('deployment.revizior.sso.key_id', ''));
        $path = trim((string) $this->config->get('deployment.revizior.sso.public_key_path', ''));
        if ($keyId !== '' && is_readable($path)) {
            $keys[$keyId] = $path;
        }

        $configured = $this->config->get('deployment.revizior.sso.public_keys', []);
        foreach (is_array($configured) ? $configured : [] as $id => $file) {
            if (!is_string($id) || !is_string($file) || trim($id) === '' || !is_readable(trim($file))) {
                continue;
            }
            $keys[trim($id)] = trim($file);
        }

        if ($keys !== []) {
            return $keys;
        }

        $serviceKeyId = trim((string) $this->config->get('deployment.revizior.service_auth.key_id', ''));
        $servicePath = trim((string) $this->config->get('deployment.revizior.service_auth.public_key_path', ''));

        return $serviceKeyId !== '' && is_readable($servicePath) ? [$serviceKeyId => $servicePath] : [];
    }

    private function publicKey(string $keyId): JWK
    {
        if (isset($this->publicKeys[$keyId])) {
            return $this->publicKeys[$keyId];
        }
        $path = $this->acceptedKeys()[$keyId] ?? null;
        if ($path === null) {
            throw ReviziorSsoException::invalidTicket();
        }
        try {
            return $this->publicKeys[$keyId] = JWKFactory::createFromKeyFile($path, null, [
                'alg' => 'RS256',
                'kid' => $keyId,
                'use' => 'sig',
            ]);
        } catch (Throwable) {
            throw ReviziorSsoException::unavailable();
        }
    }
}
