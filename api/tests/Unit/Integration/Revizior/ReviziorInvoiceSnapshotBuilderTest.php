<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceSnapshotBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviziorInvoiceSnapshotBuilderTest extends TestCase
{
    private const KEY = '60000000-0000-4000-8000-000000000001';

    public function testDraftSnapshotMatchesContractShape(): void
    {
        $snapshot = $this->builder()->build($this->invoice(), self::KEY, 0, '2026-08-30');

        $expected = json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/source/revizior-integration/contract/v1/invoice-snapshot.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        )['data'];
        $expected['sequence'] = 0;
        self::assertSame($expected, $snapshot);
    }

    /** @return iterable<string,array{array<string,mixed>,string,string}> */
    public static function statuses(): iterable
    {
        yield 'issued before due' => [['status' => 'issued'], '2026-09-01', 'issued'];
        yield 'issued after due' => [['status' => 'issued'], '2026-09-20', 'overdue'];
        yield 'sent partially paid' => [['status' => 'sent', 'paid_total' => 1000.0], '2026-09-01', 'partially_paid'];
        yield 'issued fully paid amount' => [['status' => 'paid', 'paid_total' => 5445.0], '2026-09-20', 'paid'];
        yield 'reminded' => [['status' => 'reminded'], '2026-09-20', 'overdue'];
        yield 'paid' => [['status' => 'paid', 'paid_total' => 5445.0], '2026-09-20', 'paid'];
        yield 'cancelled' => [['status' => 'cancelled'], '2026-09-20', 'cancelled'];
    }

    /** @param array<string,mixed> $overrides */
    #[DataProvider('statuses')]
    public function testStatusMapping(array $overrides, string $today, string $expected): void
    {
        $snapshot = $this->builder()->build($overrides + $this->invoice(), self::KEY, 3, $today);
        self::assertSame($expected, $snapshot['status']);
        self::assertSame($overrides['status'], $snapshot['rawStatus']);
        self::assertSame(3, $snapshot['sequence']);
        if ($overrides['status'] === 'cancelled') {
            self::assertSame(0, $snapshot['amountDueMinor']);
        }
    }

    /** Zbývající částka odečítá platby — jinak by consumer ukazoval plnou fakturu. */
    public function testAmountDueSubtractsPayments(): void
    {
        $partial = $this->builder()->build(['status' => 'sent', 'paid_total' => 1000.0] + $this->invoice(), self::KEY, 2, '2026-09-01');
        self::assertSame(544500, $partial['totalMinor']);
        self::assertSame(444500, $partial['amountDueMinor']);

        $paid = $this->builder()->build(['status' => 'paid', 'paid_total' => 5445.0] + $this->invoice(), self::KEY, 3, '2026-09-20');
        self::assertSame(0, $paid['amountDueMinor']);

        $overpaid = $this->builder()->build(['status' => 'paid', 'paid_total' => 6000.0] + $this->invoice(), self::KEY, 4, '2026-09-20');
        self::assertSame(0, $overpaid['amountDueMinor'], 'Přeplatek nesmí dát zápornou částku.');
    }

    public function testIssuedInvoiceExposesNumberEditPathAndPublicUrl(): void
    {
        $snapshot = $this->builder()->build(
            ['status' => 'issued', 'varsymbol' => '2026001', 'public_token' => 'tok'] + $this->invoice(),
            self::KEY,
            1,
            '2026-09-01',
        );
        self::assertSame('2026001', $snapshot['invoiceNumber']);
        self::assertSame('/invoices/789', $snapshot['editPath']);
        self::assertSame('http://localhost:8080/invoice/tok', $snapshot['publicUrl']);
    }

    private function builder(): ReviziorInvoiceSnapshotBuilder
    {
        return new ReviziorInvoiceSnapshotBuilder(new Config(['app' => ['url' => 'http://localhost:8080/']]));
    }

    /** @return array<string,mixed> */
    private function invoice(): array
    {
        return [
            'id' => 789,
            'status' => 'draft',
            'varsymbol' => null,
            'currency' => 'CZK',
            'currency_decimals' => 2,
            'total_with_vat' => 5445.0,
            'amount_to_pay' => 5445.0,
            'paid_total' => 0.0,
            'issue_date' => '2026-08-30',
            'due_date' => '2026-09-13',
            'public_token' => null,
        ];
    }
}
