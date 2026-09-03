<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Revizior\Security\ReviziorSsoException;

/**
 * Kam smí vést odkaz „Zpět do ReviziORu" (R4, §2.14).
 *
 * Porovnává se **celý origin** (scheme + host + port), ne jen host: `http` na
 * povoleném hostu je pořád downgrade a `evil.app.revizior.cz.example` obsahuje
 * povolený host jako podřetězec. Povolené originy vznikají z `app_url`
 * a `allowed_return_hosts` (těm se doplní `https`).
 *
 * ## Výjimka pro lokální vývoj
 *
 * `allow_insecure_return` pustí `http` **jen** na loopback host a **jen** mimo
 * produkční `app.env`. Dvě nezávislé podmínky schválně: přepnutí jedné samo
 * o sobě produkci neotevře. Bez toho by se cross-repo SSO nedalo vyzkoušet
 * lokálně, kde consumer běží na `http://localhost`.
 */
final class ReviziorReturnUrlPolicy
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    public function __construct(private readonly Config $config) {}

    /** Ověří návratovou URL a vrátí ji v normalizované podobě. */
    public function assertAllowed(string $returnTo): string
    {
        if ($returnTo === '' || strlen($returnTo) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $returnTo) === 1) {
            throw ReviziorSsoException::forbidden('sso_return_url_forbidden');
        }
        $parts = parse_url($returnTo);
        if (!is_array($parts) || isset($parts['user'], $parts['pass'])) {
            throw ReviziorSsoException::forbidden('sso_return_url_forbidden');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw ReviziorSsoException::forbidden('sso_return_url_forbidden');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (!in_array($origin, $this->allowedOrigins(), true)) {
            throw ReviziorSsoException::forbidden('sso_return_url_forbidden');
        }

        return $returnTo;
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        $origins = [];
        $appUrl = trim((string) $this->config->get('deployment.revizior.app_url', ''));
        if ($appUrl !== '') {
            $parts = parse_url($appUrl);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $origins[] = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host'])
                    . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
            }
        }

        $hosts = $this->config->get('deployment.revizior.allowed_return_hosts', []);
        foreach (is_array($hosts) ? $hosts : [] as $host) {
            if (!is_string($host) || trim($host) === '') {
                continue;
            }
            $host = strtolower(trim($host));
            $origins[] = 'https://' . $host;
            if ($this->allowsInsecureLoopback() && $this->isLoopback($host)) {
                foreach ($this->loopbackPorts() as $port) {
                    $origins[] = 'http://' . $host . $port;
                }
            }
        }

        return array_values(array_unique($origins));
    }

    private function allowsInsecureLoopback(): bool
    {
        return (bool) $this->config->get('deployment.revizior.allow_insecure_return', false)
            && (string) $this->config->get('app.env', 'production') !== 'production';
    }

    private function isLoopback(string $host): bool
    {
        return in_array($host, self::LOOPBACK_HOSTS, true);
    }

    /**
     * Vývojový consumer běží na nestandardním portu; origin musí sedět přesně,
     * takže se povolené porty berou z konfigurace (prázdné = jen výchozí 80).
     *
     * @return list<string>
     */
    private function loopbackPorts(): array
    {
        $ports = [''];
        $configured = $this->config->get('deployment.revizior.insecure_return_ports', []);
        foreach (is_array($configured) ? $configured : [] as $port) {
            $port = (int) $port;
            if ($port > 0 && $port <= 65535) {
                $ports[] = ':' . $port;
            }
        }

        return array_values(array_unique($ports));
    }
}
