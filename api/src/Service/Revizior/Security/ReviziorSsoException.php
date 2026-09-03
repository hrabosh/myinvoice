<?php

declare(strict_types=1);

namespace MyInvoice\Service\Revizior\Security;

use RuntimeException;

/**
 * Selhání browser SSO (R4).
 *
 * Kód je stabilní klíč pro logy a pro chybovou stránku; uživateli se nikdy
 * nevrací detail (proč přesně podpis neseděl), aby ticket nešlo ladit zvenčí.
 */
final class ReviziorSsoException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Podpis, čas, audience, purpose, tvar claimů — jedna odpověď pro všechno. */
    public static function invalidTicket(string $code = 'sso_ticket_invalid'): self
    {
        return new self($code, 401, 'Přihlašovací odkaz není platný.');
    }

    public static function replayed(): self
    {
        return new self('sso_ticket_replayed', 401, 'Přihlašovací odkaz už byl použitý.');
    }

    public static function forbidden(string $code): self
    {
        return new self($code, 403, 'Tento přechod do fakturace není povolený.');
    }

    public static function unavailable(): self
    {
        return new self('sso_unavailable', 503, 'Přihlášení přes ReviziOR není nakonfigurované.');
    }
}
