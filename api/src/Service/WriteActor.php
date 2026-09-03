<?php

declare(strict_types=1);

namespace MyInvoice\Service;

/**
 * Kdo zápis provedl — jen kvůli activity logu sdílených application služeb
 * (`ClientWriter`, `InvoiceDraftCreator`).
 *
 * Integrace (ReviziOR) nemá uživatele ani IP; předá {@see self::system()} a
 * audit pak nese pouze entitu a payload, stejně jako u ostatních integračních akcí.
 */
final readonly class WriteActor
{
    public function __construct(
        public ?int $userId = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    public static function system(): self
    {
        return new self();
    }
}
