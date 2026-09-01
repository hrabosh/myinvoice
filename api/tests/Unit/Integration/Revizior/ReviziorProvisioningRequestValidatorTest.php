<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningRequestValidator;
use PHPUnit\Framework\TestCase;

final class ReviziorProvisioningRequestValidatorTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';

    public function testSharedFixtureIsAcceptedAndNormalizedForStorage(): void
    {
        $input = (new ReviziorProvisioningRequestValidator())->validate(
            self::ORGANIZATION_UUID,
            $this->fixture(),
            'provision:' . self::ORGANIZATION_UUID . ':v1',
        );

        self::assertSame('00000019', $input['organization']['registrationNumber']);
        self::assertNull($input['organization']['vatStatus']);
        self::assertSame('2026-08-30 18:00:00.000000', $input['organization']['sourceUpdatedAt']);
        self::assertSame('owner@example.invalid', $input['owner']['email']);
    }

    public function testMissingNullableVatStatusUnknownFieldAndInactiveOwnerAreRejected(): void
    {
        $fixture = $this->fixture();
        unset($fixture['organization']['vatStatus']);
        $fixture['organization']['unexpected'] = true;
        $fixture['owner']['active'] = false;

        try {
            (new ReviziorProvisioningRequestValidator())->validate(self::ORGANIZATION_UUID, $fixture, '');
            self::fail('Neplatný provisioning request měl být odmítnut.');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('validation_failed', $e->errorCode);
            self::assertSame('required', $e->fields['Idempotency-Key']);
            self::assertSame('required_nullable_string', $e->fields['organization.vatStatus']);
            self::assertSame('unknown_field', $e->fields['organization.unexpected']);
            self::assertSame('must_be_true_for_provisioning', $e->fields['owner.active']);
        }
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/source/revizior-integration/contract/v1/provision-request.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }
}
