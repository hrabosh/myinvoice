<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use RuntimeException;

final class ReviziorProvisioningException extends RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        public readonly array $fields = [],
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    /** @param array<string,string> $fields */
    public static function validation(array $fields): self
    {
        return new self(
            'validation_failed',
            400,
            'Požadavek nelze zpracovat.',
            $fields,
        );
    }

    public static function conflict(string $code): self
    {
        return new self($code, 409, 'Požadavek je v konfliktu s existující vazbou.');
    }

    public static function notFound(string $code): self
    {
        return new self($code, 404, 'Požadovaná integrační vazba neexistuje.');
    }
}
