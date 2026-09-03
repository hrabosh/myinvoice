<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use RuntimeException;

/**
 * Selhání založení konceptu ve sdílené službě {@see InvoiceDraftCreator}.
 *
 * Druh (`kind`) je stabilní klíč; UI action z něj skládá dosavadní odpovědi
 * (`integrity_violation`, `validation_failed`, `client_not_found`,
 * `varsymbol_duplicate`), integrace ReviziORu názvy polí z kontraktu.
 */
final class InvoiceDraftException extends RuntimeException
{
    public const KIND_VALIDATION = 'validation';
    public const KIND_INTEGRITY = 'integrity';
    public const KIND_CLIENT_NOT_FOUND = 'client_not_found';
    public const KIND_VARSYMBOL_DUPLICATE = 'varsymbol_duplicate';

    /** @param array<string, list<string>> $fields */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, list<string>> $fields */
    public static function validation(array $fields): self
    {
        return new self(self::KIND_VALIDATION, 'Validace selhala', $fields);
    }

    public static function integrity(string $message): self
    {
        return new self(self::KIND_INTEGRITY, $message);
    }

    public static function clientNotFound(): self
    {
        return new self(self::KIND_CLIENT_NOT_FOUND, 'Klient neexistuje.');
    }

    public static function varsymbolDuplicate(string $message): self
    {
        return new self(self::KIND_VARSYMBOL_DUPLICATE, $message);
    }
}
