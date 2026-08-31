<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Service\Integration\Revizior\ReviziorContract;
use PHPUnit\Framework\TestCase;

final class ReviziorContractTest extends TestCase
{
    public function testVersionAndBasePathStayStableForV1(): void
    {
        self::assertSame('1.0', ReviziorContract::VERSION);
        self::assertSame('/api/integrations/revizior/v1', ReviziorContract::BASE_PATH);
    }
}
