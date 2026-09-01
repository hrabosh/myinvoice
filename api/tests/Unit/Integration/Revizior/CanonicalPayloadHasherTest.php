<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use InvalidArgumentException;
use MyInvoice\Service\Integration\Revizior\CanonicalPayloadHasher;
use PHPUnit\Framework\TestCase;

final class CanonicalPayloadHasherTest extends TestCase
{
    public function testProvisionFixtureMatchesSharedManifest(): void
    {
        $root = dirname(__DIR__, 5);
        $fixture = json_decode(
            (string) file_get_contents($root . '/source/revizior-integration/contract/v1/provision-request.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        $manifest = json_decode(
            (string) file_get_contents($root . '/source/revizior-integration/contract/v1/hashes.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            $manifest['fixtures']['provision-request.json'],
            (new CanonicalPayloadHasher())->prefixedHash($fixture),
        );
    }

    public function testObjectOrderUuidCaseAndEquivalentTimestampsAreCanonical(): void
    {
        $hasher = new CanonicalPayloadHasher();
        self::assertSame(
            $hasher->hash([
                'uuid' => '20000000-0000-4000-8000-00000000000A',
                'at' => '2026-08-30T20:00:00+02:00',
                'items' => [2, 1],
            ]),
            $hasher->hash([
                'items' => [2, 1],
                'at' => '2026-08-30T18:00:00Z',
                'uuid' => '20000000-0000-4000-8000-00000000000a',
            ]),
        );
    }

    public function testFloatIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CanonicalPayloadHasher())->hash(['amount' => 1.5]);
    }
}
