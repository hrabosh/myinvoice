<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Service\Integration\Revizior\ReviziorClientRequestValidator;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\TestCase;

final class ReviziorClientRequestValidatorTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';
    private const CLIENT_UUID = '40000000-0000-4000-8000-000000000001';

    public function testContractFixtureIsAcceptedAndNormalized(): void
    {
        $input = (new ReviziorClientRequestValidator())->validate(self::ORGANIZATION_UUID, self::CLIENT_UUID, $this->fixture());

        self::assertSame('Example Client s.r.o.', $input['companyName']);
        self::assertSame('00000019', $input['registrationNumber']);
        self::assertNull($input['vatNumber']);
        self::assertSame('Zkušební 10', $input['street']);
        self::assertSame('Praha', $input['city']);
        self::assertSame('11000', $input['postalCode']);
        self::assertSame('CZ', $input['countryCode']);
        self::assertSame([['type' => 'billing', 'email' => 'billing@example.invalid', 'name' => 'Testovací kontakt']], $input['contacts']);
        self::assertSame('cs', $input['language']);
        self::assertTrue($input['active']);
        self::assertSame('2026-08-31 10:00:00.000000', $input['sourceUpdatedAt']);
    }

    /** ReviziOR zná adresu jako jeden řádek: město, PSČ i země smí být `null`, ulice ne. */
    public function testAddressPartsMayBeNullButStreetIsRequired(): void
    {
        $body = $this->fixture();
        $body['address'] = ['street' => 'Nádražní 12, Brno', 'city' => null, 'postalCode' => null, 'countryCode' => null];
        $input = (new ReviziorClientRequestValidator())->validate(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);
        self::assertNull($input['city']);
        self::assertNull($input['countryCode']);

        $body['address']['street'] = null;
        $this->assertFields($body, ['address.street' => 'required_string']);
    }

    public function testEmptyStringIsNotAnAcceptableSubstituteForNull(): void
    {
        $body = $this->fixture();
        $body['vatNumber'] = '';
        $this->assertFields($body, ['vatNumber' => 'empty_string_use_null']);
    }

    public function testUnknownFieldsContactLimitAndTypesAreRejected(): void
    {
        $body = $this->fixture();
        $body['note'] = 'x';
        $body['address']['region'] = 'JMK';
        $body['contacts'] = [
            ['type' => 'technical', 'email' => 'not-an-email', 'name' => null],
            ['type' => 'billing', 'email' => 'a@example.invalid', 'name' => null],
            ['type' => 'billing', 'email' => 'b@example.invalid', 'name' => null],
            ['type' => 'billing', 'email' => 'c@example.invalid', 'name' => null],
        ];
        $body['language'] = 'de';
        $body['active'] = 'yes';
        $body['sourceUpdatedAt'] = '2026-08-31';

        $this->assertFields($body, [
            'note' => 'unknown_field',
            'address.region' => 'unknown_field',
            'contacts' => 'too_many',
            'language' => 'invalid_value',
            'active' => 'required_boolean',
            'sourceUpdatedAt' => 'must_be_rfc3339',
        ]);

        $body['contacts'] = [['type' => 'technical', 'email' => 'not-an-email', 'name' => null, 'phone' => '1']];
        $body['language'] = 'cs';
        $body['active'] = true;
        $body['sourceUpdatedAt'] = '2026-08-31T10:00:00Z';
        unset($body['note'], $body['address']['region']);
        $this->assertFields($body, [
            'contacts.0.phone' => 'unknown_field',
            'contacts.0.type' => 'invalid_value',
            'contacts.0.email' => 'invalid_email',
        ]);
    }

    public function testCountryCodeMustBeTwoUppercaseLetters(): void
    {
        $body = $this->fixture();
        $body['address']['countryCode'] = 'cz';
        $this->assertFields($body, ['address.countryCode' => 'invalid_country_code']);
    }

    public function testPathUuidsAreValidated(): void
    {
        try {
            (new ReviziorClientRequestValidator())->validate('org', 'client', $this->fixture());
            self::fail('expected validation failure');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('client_validation_failed', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
            self::assertSame(['organizationUuid' => 'must_be_uuid', 'clientUuid' => 'must_be_uuid'], $e->fields);
        }
    }

    /** @param array<string,mixed> $body @param array<string,string> $expected */
    private function assertFields(array $body, array $expected): void
    {
        try {
            (new ReviziorClientRequestValidator())->validate(self::ORGANIZATION_UUID, self::CLIENT_UUID, $body);
            self::fail('expected validation failure');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('client_validation_failed', $e->errorCode);
            self::assertSame($expected, $e->fields);
        }
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/source/revizior-integration/contract/v1/client-upsert-request.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }
}
