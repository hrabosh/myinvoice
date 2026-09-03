<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Revizior;

use MyInvoice\Action\Revizior\SyncReviziorClientAction;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSyncResult;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SyncReviziorClientActionTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';
    private const CLIENT_UUID = '40000000-0000-4000-8000-000000000001';
    private const REQUEST_ID = '10000000-0000-4000-8000-000000000003';

    public function testCreatedClientReturns201WithContractEnvelope(): void
    {
        $synchronizer = $this->createMock(ReviziorClientSynchronizer::class);
        $synchronizer->expects(self::once())
            ->method('upsert')
            ->with(self::ORGANIZATION_UUID, self::CLIENT_UUID, ['specVersion' => '1.0'])
            ->willReturn(new ReviziorClientSyncResult([
                'clientUuid' => self::CLIENT_UUID,
                'externalClientId' => '456',
                'operation' => 'created',
                'payloadHash' => 'sha256:' . str_repeat('a', 64),
            ]));

        $response = $this->invoke($synchronizer, ['specVersion' => '1.0']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('1.0', $response->getHeaderLine('X-Revizior-Contract-Version'));
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('456', $body['data']['externalClientId']);
        self::assertSame('created', $body['data']['operation']);
        self::assertSame(self::REQUEST_ID, $body['meta']['requestId']);
    }

    public function testUnchangedClientReturns200(): void
    {
        $synchronizer = $this->createStub(ReviziorClientSynchronizer::class);
        $synchronizer->method('upsert')->willReturn(new ReviziorClientSyncResult([
            'clientUuid' => self::CLIENT_UUID,
            'externalClientId' => '456',
            'operation' => 'unchanged',
            'payloadHash' => 'sha256:' . str_repeat('a', 64),
        ]));

        self::assertSame(200, $this->invoke($synchronizer, ['specVersion' => '1.0'])->getStatusCode());
    }

    public function testValidationUsesClientSpecificErrorCode(): void
    {
        $synchronizer = $this->createStub(ReviziorClientSynchronizer::class);
        $synchronizer->method('upsert')->willThrowException(
            ReviziorProvisioningException::clientValidation(['address.street' => 'required']),
        );

        $response = $this->invoke($synchronizer, ['specVersion' => '1.0']);
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('client_validation_failed', $body['error']['code']);
        self::assertSame(['address.street' => 'required'], $body['error']['fields']);
        self::assertFalse($body['error']['retryable']);
    }

    public function testMissingJsonBodyIsRejectedBeforeTheService(): void
    {
        $synchronizer = $this->createMock(ReviziorClientSynchronizer::class);
        $synchronizer->expects(self::never())->method('upsert');

        $response = $this->invoke($synchronizer, null);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testUnexpectedFailureIsRetryable503WithoutInternals(): void
    {
        $synchronizer = $this->createStub(ReviziorClientSynchronizer::class);
        $synchronizer->method('upsert')->willThrowException(new \RuntimeException('SQLSTATE secret'));

        $response = $this->invoke($synchronizer, ['specVersion' => '1.0']);
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('provider_temporarily_unavailable', $body['error']['code']);
        self::assertTrue($body['error']['retryable']);
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getBody());
    }

    /** @param array<string,mixed>|null $body */
    private function invoke(ReviziorClientSynchronizer $synchronizer, ?array $body): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/integrations/revizior/v1/organizations/' . self::ORGANIZATION_UUID . '/clients/' . self::CLIENT_UUID)
            ->withHeader('X-Request-Id', self::REQUEST_ID);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return (new SyncReviziorClientAction($synchronizer, new NullLogger()))(
            $request,
            (new ResponseFactory())->createResponse(),
            ['organizationUuid' => self::ORGANIZATION_UUID, 'clientUuid' => self::CLIENT_UUID],
        );
    }
}
