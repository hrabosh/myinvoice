<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftRequestValidator;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\TestCase;

final class ReviziorInvoiceDraftRequestValidatorTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';

    public function testContractFixtureIsAcceptedAndNormalized(): void
    {
        $input = (new ReviziorInvoiceDraftRequestValidator())->validate(self::ORGANIZATION_UUID, $this->fixture(), 'invoice-draft:60000000-0000-4000-8000-000000000001');

        self::assertSame('60000000-0000-4000-8000-000000000001', $input['externalInvoiceKey']);
        self::assertSame('40000000-0000-4000-8000-000000000001', $input['clientUuid']);
        self::assertSame('invoice', $input['invoiceType']);
        self::assertSame('CZK', $input['currency']);
        self::assertSame(['2026-08-30', '2026-08-30', '2026-09-13'], [$input['issueDate'], $input['taxDate'], $input['dueDate']]);
        self::assertFalse($input['pricesIncludeVat']);
        self::assertCount(1, $input['items']);
        $item = $input['items'][0];
        self::assertSame('REV_ELEKTRO_ZAKLAD', $item['priceListCode']);
        self::assertSame('1.000', $item['quantity']);
        self::assertSame('4500.00', $item['unitPrice']);
        self::assertSame('21.00', $item['vatRate']);
        self::assertSame([['type' => 'revision_report', 'uuid' => '70000000-0000-4000-8000-000000000001']], $item['sourceReferences']);
    }

    public function testDecimalsMustBeStringsAndAmountsCannotBeFloats(): void
    {
        $body = $this->fixture();
        $body['items'][0]['unitPrice'] = 4500.0;
        $body['items'][0]['quantity'] = '0.000';
        $this->assertFields($body, ['items.0.quantity' => 'must_not_be_zero', 'items.0.unitPrice' => 'must_be_decimal_string'], 'key');
    }

    public function testIdempotencyKeyCreditNoteAndDatesAreValidated(): void
    {
        $body = $this->fixture();
        $body['invoiceType'] = 'credit_note';
        $body['dueDate'] = '2026-08-01';
        $body['items'][0]['sourceReferences'][0]['type'] = 'email';
        $body['extra'] = 1;
        $this->assertFields($body, [
            'Idempotency-Key' => 'required',
            'extra' => 'unknown_field',
            'invoiceType' => 'unsupported',
            'dueDate' => 'before_issue_date',
            'items.0.sourceReferences.0.type' => 'invalid_value',
        ], '');
    }

    public function testDuplicateLineKeysAndMissingNullableFieldsAreRejected(): void
    {
        $body = $this->fixture();
        $second = $body['items'][0];
        unset($second['priceListCode']);
        $body['items'][] = $second;
        unset($body['taxDate']);
        $this->assertFields($body, [
            'taxDate' => 'required_nullable',
            'items.1.externalLineKey' => 'duplicate',
            'items.1.priceListCode' => 'required_nullable',
        ], 'key');
    }

    public function testProformaAcceptsNullTaxDate(): void
    {
        $body = $this->fixture();
        $body['invoiceType'] = 'proforma';
        $body['taxDate'] = null;
        $input = (new ReviziorInvoiceDraftRequestValidator())->validate(self::ORGANIZATION_UUID, $body, 'key');
        self::assertNull($input['taxDate']);
    }

    /** @param array<string,mixed> $body @param array<string,string> $expected */
    private function assertFields(array $body, array $expected, string $key): void
    {
        try {
            (new ReviziorInvoiceDraftRequestValidator())->validate(self::ORGANIZATION_UUID, $body, $key);
            self::fail('expected validation failure');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('invoice_validation_failed', $e->errorCode);
            self::assertSame($expected, $e->fields);
        }
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/source/revizior-integration/contract/v1/invoice-draft-request.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }
}
