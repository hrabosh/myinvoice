<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

final readonly class ReviziorProvisioningResult
{
    /** @param array<string,string> $data */
    public function __construct(
        public array $data,
        public bool $created,
    ) {}
}
