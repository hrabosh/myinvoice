<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Settings;

use MyInvoice\Action\Settings\SupplierMembersAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PermissionPolicy;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class SupplierMembersActionTest extends TestCase
{
    public function testAccountantCannotListMembers(): void
    {
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::never())->method('listForSupplier');

        $response = $this->action($memberships)->list(
            $this->request('GET', '/api/settings/supplier/members', 'accountant'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testOwnerListsOnlyCurrentSupplierMembers(): void
    {
        $members = [[
            'user_id' => 19,
            'email' => 'member@example.invalid',
            'name' => 'Synthetic Member',
            'role' => 'accountant',
            'is_active' => true,
        ]];
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::once())->method('listForSupplier')->with(7)->willReturn($members);

        $response = $this->action($memberships)->list(
            $this->request('GET', '/api/settings/supplier/members', 'supplier_owner'),
            (new ResponseFactory())->createResponse(),
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($members, $body);
    }

    public function testOwnerCannotChangeOwnMembership(): void
    {
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::never())->method('updateRoleForSupplier');
        $request = $this->request('PUT', '/api/settings/supplier/members/17', 'supplier_owner')
            ->withParsedBody(['role' => 'readonly']);

        $response = $this->action($memberships)->update(
            $request,
            (new ResponseFactory())->createResponse(),
            ['userId' => '17'],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('self_membership_change_forbidden', $this->errorCode($response));
    }

    public function testRoleChangeRevokesTargetSessions(): void
    {
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::once())->method('updateRoleForSupplier')
            ->with(7, 19, 'readonly')->willReturn(true);
        $memberships->expects(self::once())->method('listForSupplier')->with(7)->willReturn([]);
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::once())->method('destroyAllForUser')->with(19)->willReturn(2);
        $request = $this->request('PUT', '/api/settings/supplier/members/19', 'supplier_owner')
            ->withParsedBody(['role' => 'readonly']);

        $response = $this->action($memberships, $sessions)->update(
            $request,
            (new ResponseFactory())->createResponse(),
            ['userId' => '19'],
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $body['revoked_sessions']);
    }

    public function testLastOwnerCannotBeRemoved(): void
    {
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::once())->method('removeForSupplier')
            ->with(7, 19)->willThrowException(new \DomainException('last_supplier_owner'));
        $sessions = $this->createMock(SessionManager::class);
        $sessions->expects(self::never())->method('destroyAllForUser');

        $response = $this->action($memberships, $sessions)->delete(
            $this->request('DELETE', '/api/settings/supplier/members/19', 'supplier_owner'),
            (new ResponseFactory())->createResponse(),
            ['userId' => '19'],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('last_supplier_owner', $this->errorCode($response));
    }

    private function action(
        UserSupplierRepository $memberships,
        ?SessionManager $sessions = null,
    ): SupplierMembersAction {
        return new SupplierMembersAction(
            $memberships,
            new PermissionPolicy(),
            $sessions ?? $this->createStub(SessionManager::class),
            $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class),
        );
    }

    private function request(string $method, string $path, string $supplierRole): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'role' => $supplierRole === 'supplier_owner' ? 'accountant' : $supplierRole,
                'platform_role' => 'readonly',
                'supplier_role' => $supplierRole,
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7);
    }

    private function errorCode(\Psr\Http\Message\ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        return (string) $body['error']['code'];
    }
}
