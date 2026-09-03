<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

final readonly class ReviziorClientSyncResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    /** @param array{clientUuid:string,externalClientId:string,operation:string,payloadHash:string} $data */
    public function __construct(
        public array $data,
    ) {}

    public function created(): bool
    {
        return $this->data['operation'] === self::CREATED;
    }
}
