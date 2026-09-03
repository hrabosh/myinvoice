<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Revizior;

use MyInvoice\Action\Revizior\CreateReviziorInvoiceDraftAction;
use MyInvoice\Action\Revizior\GetReviziorInvoiceAction;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftResult;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftService;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ReviziorInvoiceActionsTest extends TestCase
{
    private const ORGANIZATION_UUID = '30000000-0000-4000-8000-000000000001';
    private const KEY = '60000000-0000-4000-8000-000000000001';
    private const REQUEST_ID = '10000000-0000-4000-8000-000000000005';

    public function testCreatedDraftReturns201AndStoredReplayReturns200(): void
    {
        $service = $this->createMock(ReviziorInvoiceDraftService::class);
        $service->expects(self::exactly(2))
            ->method('create')
            ->with(self::ORGANIZATION_UUID, ['specVersion' => '1.0'], 'invoice-draft:' . self::KEY)
            ->willReturnOnConsecutiveCalls(
                new ReviziorInvoiceDraftResult(['invoiceId' => '789', 'status' => 'draft'], true),
                new ReviziorInvoiceDraftResult(['invoiceId' => '789', 'status' => 'draft'], false),
            );
        $action = new CreateReviziorInvoiceDraftAction($service, new NullLogger());

        $first = $action($this->request('POST')->withParsedBody(['specVersion' => '1.0']), (new ResponseFactory())->createResponse(), ['organizationUuid' => self::ORGANIZATION_UUID]);
        $second = $action($this->request('POST')->withParsedBody(['specVersion' => '1.0']), (new ResponseFactory())->createResponse(), ['organizationUuid' => self::ORGANIZATION_UUID]);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        $body = json_decode((string) $first->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('789', $body['data']['invoiceId']);
        self::assertSame(self::REQUEST_ID, $body['meta']['requestId']);
    }

    public function testConflictAndValidationEnvelopes(): void
    {
        $service = $this->createStub(ReviziorInvoiceDraftService::class);
        $service->method('create')->willReturnOnConsecutiveCalls(
            self::throwException(ReviziorProvisioningException::conflict('idempotency_conflict')),
            self::throwException(ReviziorProvisioningException::invoiceValidation(['items.0.vatRate' => 'unknown_vat_rate'])),
        );
        $action = new CreateReviziorInvoiceDraftAction($service, new NullLogger());

        $conflict = $action($this->request('POST')->withParsedBody(['specVersion' => '1.0']), (new ResponseFactory())->createResponse(), ['organizationUuid' => self::ORGANIZATION_UUID]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('idempotency_conflict', json_decode((string) $conflict->getBody(), true)['error']['code']);

        $validation = $action($this->request('POST')->withParsedBody(['specVersion' => '1.0']), (new ResponseFactory())->createResponse(), ['organizationUuid' => self::ORGANIZATION_UUID]);
        self::assertSame(400, $validation->getStatusCode());
        $body = json_decode((string) $validation->getBody(), true);
        self::assertSame('invoice_validation_failed', $body['error']['code']);
        self::assertSame(['items.0.vatRate' => 'unknown_vat_rate'], $body['error']['fields']);
    }

    public function testGetReturnsSnapshotAndNotFound(): void
    {
        $service = $this->createStub(ReviziorInvoiceDraftService::class);
        $service->method('snapshot')->willReturnOnConsecutiveCalls(
            ['invoiceId' => '789', 'sequence' => 2],
            self::throwException(ReviziorProvisioningException::notFound('invoice_not_found')),
        );
        $action = new GetReviziorInvoiceAction($service, new NullLogger());
        $args = ['organizationUuid' => self::ORGANIZATION_UUID, 'externalInvoiceKey' => self::KEY];

        $ok = $action($this->request('GET'), (new ResponseFactory())->createResponse(), $args);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(2, json_decode((string) $ok->getBody(), true)['data']['sequence']);

        $missing = $action($this->request('GET'), (new ResponseFactory())->createResponse(), $args);
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('invoice_not_found', json_decode((string) $missing->getBody(), true)['error']['code']);
    }

    public function testUnexpectedFailureIsRetryable503(): void
    {
        $service = $this->createStub(ReviziorInvoiceDraftService::class);
        $service->method('create')->willThrowException(new \RuntimeException('SQLSTATE secret'));
        $response = (new CreateReviziorInvoiceDraftAction($service, new NullLogger()))(
            $this->request('POST')->withParsedBody(['specVersion' => '1.0']),
            (new ResponseFactory())->createResponse(),
            ['organizationUuid' => self::ORGANIZATION_UUID],
        );
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getBody());
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/integrations/revizior/v1/organizations/' . self::ORGANIZATION_UUID . '/invoice-drafts')
            ->withHeader('X-Request-Id', self::REQUEST_ID)
            ->withHeader('Idempotency-Key', 'invoice-draft:' . self::KEY);
    }
}
