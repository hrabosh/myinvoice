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

    /** @param array<string,string> $fields */
    public static function clientValidation(array $fields): self
    {
        return new self('client_validation_failed', 400, 'Klienta nelze v této podobě uložit.', $fields);
    }

    /** @param array<string,string> $fields */
    public static function invoiceValidation(array $fields): self
    {
        return new self('invoice_validation_failed', 400, 'Koncept dokladu nelze v této podobě založit.', $fields);
    }

    public static function attachmentInvalid(string $detail): self
    {
        return new self('attachment_invalid', 400, 'Přílohu nelze přijmout.', ['attachment' => $detail]);
    }

    public static function attachmentTooLarge(): self
    {
        return new self('attachment_too_large', 413, 'Příloha je příliš velká.');
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
