<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use MyInvoice\Service\Integration\Revizior\ReviziorUserRequestValidator;
use PHPUnit\Framework\TestCase;

final class ReviziorUserRequestValidatorTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';
    private const USER_UUID = '20000000-0000-4000-8000-000000000002';

    public function testFrozenFixtureIsAcceptedAndNormalized(): void
    {
        $result = (new ReviziorUserRequestValidator())->validate(
            self::ORGANIZATION_UUID,
            strtoupper(self::USER_UUID),
            $this->fixture(),
        );

        self::assertSame(self::ORGANIZATION_UUID, $result['organizationUuid']);
        self::assertSame(self::USER_UUID, $result['userUuid']);
        self::assertSame('accountant@example.invalid', $result['email']);
        self::assertSame('accountant', $result['role']);
        self::assertTrue($result['active']);
        self::assertSame('2026-08-31 09:35:00.000000', $result['sourceUpdatedAt']);
    }

    public function testUnknownFieldPathMismatchAndAdminRoleAreRejected(): void
    {
        $body = $this->fixture();
        $body['userUuid'] = '20000000-0000-4000-8000-000000000099';
        $body['role'] = 'admin';
        $body['unexpected'] = true;

        try {
            (new ReviziorUserRequestValidator())->validate(self::ORGANIZATION_UUID, self::USER_UUID, $body);
            self::fail('Neplatný user payload měl být odmítnut.');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame(400, $e->httpStatus);
            self::assertSame('unknown_field', $e->fields['unexpected']);
            self::assertSame('must_match_path', $e->fields['body.userUuid']);
            self::assertSame('invalid_value', $e->fields['role']);
        }
    }

    public function testInactiveSnapshotIsValid(): void
    {
        $body = $this->fixture();
        $body['active'] = false;
        $result = (new ReviziorUserRequestValidator())->validate(self::ORGANIZATION_UUID, self::USER_UUID, $body);
        self::assertFalse($result['active']);
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/source/revizior-integration/contract/v1/user-upsert.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
    }
}
