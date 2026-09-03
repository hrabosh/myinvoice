<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

final readonly class ReviziorUserProvisioningResult
{
    /** @param array{externalUserId:string,role:string,active:bool} $data */
    public function __construct(
        public array $data,
        public bool $created,
    ) {}
}
