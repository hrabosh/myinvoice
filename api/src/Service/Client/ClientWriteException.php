<?php

declare(strict_types=1);

namespace MyInvoice\Service\Client;

use RuntimeException;

/**
 * Selhání zápisu klienta ve sdílené službě {@see ClientWriter}.
 *
 * Druh (`kind`) je stabilní klíč, podle kterého si každé volací místo vybere
 * vlastní HTTP odpověď: UI action vrací dosavadní `validation_failed` /
 * `integrity_violation` / `invalid_email_contacts`, integrační endpoint
 * ReviziORu mapuje pole na názvy z kontraktu.
 */
final class ClientWriteException extends RuntimeException
{
    public const KIND_VALIDATION = 'validation';
    public const KIND_INTEGRITY = 'integrity';
    public const KIND_CONTACTS = 'contacts';
    public const KIND_NOT_FOUND = 'not_found';

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

    public static function contacts(string $message): self
    {
        return new self(self::KIND_CONTACTS, $message);
    }

    public static function notFound(): self
    {
        return new self(self::KIND_NOT_FOUND, 'Klient nenalezen.');
    }
}
