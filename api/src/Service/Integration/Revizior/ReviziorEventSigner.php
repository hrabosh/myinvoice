<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use RuntimeException;

/**
 * Podpis odchozí události (R5, §2.16).
 *
 * Podepisují se **přesné bajty těla**, které jdou po drátě, ne znovu
 * zakódovaný JSON: consumer ověřuje raw body a přeformátování by podpis
 * rozbilo (nebo, hůř, podepsalo něco jiného, než se pak zpracuje).
 *
 * Formát je RS256 nad raw tělem, výsledek base64url v hlavičce
 * `X-MyInvoice-Signature`, `kid` v `X-MyInvoice-Key-Id` — přesně to, co
 * consumer ověřuje (`RsaJwsVerifier::verifyDetached`). Zadání tomu říká
 * „detached JWS"; obě strany implementují tenhle jednodušší tvar bez
 * JOSE hlavičky a kontrakt v1 nic víc nespecifikuje.
 */
final class ReviziorEventSigner
{
    public function __construct(private readonly Config $config) {}

    public function isConfigured(): bool
    {
        return $this->keyId() !== '' && is_readable($this->privateKeyPath());
    }

    public function keyId(): string
    {
        return trim((string) $this->config->get('deployment.revizior.callback.key_id', ''));
    }

    public function sign(string $rawBody): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Podpisový klíč pro ReviziOR callback není nakonfigurovaný.');
        }
        $pem = file_get_contents($this->privateKeyPath());
        if ($pem === false) {
            throw new RuntimeException('Podpisový klíč pro ReviziOR callback nelze načíst.');
        }
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('Podpisový klíč pro ReviziOR callback není platný.');
        }
        $signature = '';
        if (!openssl_sign($rawBody, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Podpis události se nezdařil.');
        }

        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function privateKeyPath(): string
    {
        return trim((string) $this->config->get('deployment.revizior.callback.private_key_path', ''));
    }
}
