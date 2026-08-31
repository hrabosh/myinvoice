<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Service\Deployment\DeploymentCapabilities;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class ManagedModeGuardMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> */
    private const DISABLED_MODULE_PREFIXES = [
        '/api/purchase-invoices' => 'purchaseInvoices',
        '/api/dashboard/purchase-summary' => 'purchaseInvoices',
        '/api/reports' => 'tax',
        '/api/tax' => 'tax',
        '/api/logbook' => 'logbook',
    ];

    public function __construct(
        private readonly DeploymentCapabilities $capabilities,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->capabilities->isStandalone()) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($this->isSetupPath($path)) {
            return $this->disabled($this->responseFactory->createResponse(404), 'managed_setup_disabled');
        }
        if ($this->isLocalAuthPath($path)) {
            return $this->disabled($this->responseFactory->createResponse(404), 'managed_local_auth_disabled');
        }
        if (str_starts_with($path, '/api/admin/update')) {
            return $this->disabled($this->responseFactory->createResponse(404), 'managed_self_update_disabled');
        }
        if (str_starts_with($path, '/api/admin/myucto-upgrade')) {
            return $this->disabled($this->responseFactory->createResponse(404), 'managed_myucto_upgrade_disabled');
        }

        foreach (self::DISABLED_MODULE_PREFIXES as $prefix => $module) {
            if (str_starts_with($path, $prefix) && !$this->capabilities->showsModule($module)) {
                return $this->disabled($this->responseFactory->createResponse(404), 'managed_module_disabled');
            }
        }

        return $handler->handle($request);
    }

    private function isSetupPath(string $path): bool
    {
        return $path !== '/api/auth/setup-status'
            && str_starts_with($path, '/api/auth/setup');
    }

    private function isLocalAuthPath(string $path): bool
    {
        return in_array($path, [
            '/api/auth/login',
            '/api/auth/webauthn/login/options',
            '/api/auth/webauthn/login/verify',
            '/api/auth/forgot',
            '/api/auth/reset',
        ], true);
    }

    private function disabled(Response $response, string $code): Response
    {
        return Json::error(
            $response,
            $code,
            'Tato funkce není v režimu ReviziOR Fakturace dostupná.',
            404,
        );
    }
}
