<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Integration\Revizior;

use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationUpdateRequestValidator;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\TestCase;

final class ReviziorOrganizationUpdateRequestValidatorTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';

    public function testFrozenUpdateFixtureIsAccepted(): void
    {
        $result = (new ReviziorOrganizationUpdateRequestValidator())->validate(
            self::ORGANIZATION_UUID,
            $this->fixture(),
        );

        self::assertSame('Example Revize s.r.o.', $result['name']);
        self::assertSame('CZ', $result['countryCode']);
        self::assertNull($result['vatStatus']);
        self::assertTrue($result['active']);
    }

    public function testInactiveOrganizationIsAllowedButVatStatusMustStayExplicit(): void
    {
        $body = $this->fixture();
        $body['organization']['active'] = false;
        unset($body['organization']['vatStatus']);

        try {
            (new ReviziorOrganizationUpdateRequestValidator())->validate(self::ORGANIZATION_UUID, $body);
            self::fail('Chybějící explicitní vatStatus měl být odmítnut.');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('required_nullable_string', $e->fields['organization.vatStatus']);
        }
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 5) . '/source/revizior-integration/contract/v1/organization-update.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
    }
}
