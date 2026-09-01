<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Revizior;

use MyInvoice\Action\Revizior\ProvisionReviziorOrganizationAction;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ProvisionReviziorOrganizationActionTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';
    private const REQUEST_ID = '10000000-0000-4000-8000-000000000002';

    public function testCreatedResponseUsesStableEnvelopeAndHeaders(): void
    {
        $provisioner = $this->createMock(ReviziorOrganizationProvisioner::class);
        $provisioner->expects(self::once())
            ->method('provision')
            ->with(self::ORGANIZATION_UUID, ['specVersion' => '1.0'], 'provision:key')
            ->willReturn(new ReviziorProvisioningResult([
                'organizationUuid' => self::ORGANIZATION_UUID,
                'supplierId' => '123',
            ], true));

        $response = (new ProvisionReviziorOrganizationAction($provisioner, new NullLogger()))(
            $this->request()->withParsedBody(['specVersion' => '1.0']),
            (new ResponseFactory())->createResponse(),
            ['organizationUuid' => self::ORGANIZATION_UUID],
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('1.0', $response->getHeaderLine('X-Revizior-Contract-Version'));
        self::assertSame(self::REQUEST_ID, $response->getHeaderLine('X-Request-Id'));
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('1.0', $body['specVersion']);
        self::assertSame('123', $body['data']['supplierId']);
        self::assertSame(self::REQUEST_ID, $body['meta']['requestId']);
    }

    public function testValidationFieldsUseStableErrorEnvelope(): void
    {
        $provisioner = $this->createMock(ReviziorOrganizationProvisioner::class);
        $provisioner->expects(self::once())
            ->method('provision')
            ->willThrowException(
                ReviziorProvisioningException::validation(['owner.email' => 'invalid_email']),
            );

        $response = (new ProvisionReviziorOrganizationAction($provisioner, new NullLogger()))(
            $this->request()->withParsedBody(['specVersion' => '1.0']),
            (new ResponseFactory())->createResponse(),
            ['organizationUuid' => self::ORGANIZATION_UUID],
        );
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame('invalid_email', $body['error']['fields']['owner.email']);
        self::assertFalse($body['error']['retryable']);
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/integrations/revizior/v1/organizations/' . self::ORGANIZATION_UUID . '/provision')
            ->withHeader('X-Request-Id', self::REQUEST_ID)
            ->withHeader('Idempotency-Key', 'provision:key');
    }
}
