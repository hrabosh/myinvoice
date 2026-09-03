<?php

declare(strict_types=1);

namespace MyInvoice\Service\Revizior\Security;

use RuntimeException;

final class ReviziorServiceAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function unauthorized(string $code = 'service_token_invalid'): self
    {
        return new self($code, 401, 'Pověření služby není platné.');
    }

    public static function forbidden(string $code): self
    {
        return new self($code, 403, 'Pověření služby nemá požadovaný rozsah.');
    }

    public static function unavailable(): self
    {
        return new self(
            'provider_temporarily_unavailable',
            503,
            'Integrace ReviziOR není nakonfigurovaná.',
            true,
        );
    }
}
