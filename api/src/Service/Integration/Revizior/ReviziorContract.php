<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

/**
 * Stable identifiers shared by provider fixtures and the future runtime API.
 *
 * R0 deliberately exposes no endpoint. Keeping the version and base path in
 * one place prevents later actions from introducing subtly different values.
 */
final class ReviziorContract
{
    public const VERSION = '1.0';
    public const BASE_PATH = '/api/integrations/revizior/v1';

    private function __construct() {}
}
