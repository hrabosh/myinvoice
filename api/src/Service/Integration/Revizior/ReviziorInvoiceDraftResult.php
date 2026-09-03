<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

final readonly class ReviziorInvoiceDraftResult
{
    /** @param array<string,mixed> $data snapshot dokladu */
    public function __construct(
        public array $data,
        public bool $created,
    ) {}
}
