<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RoleMiddlewareTest extends TestCase
{
    public function testAccountantCanMutateOwnSigningProfilesRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/settings/signing/profiles', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAccountantCanMutateOwnSigningProfileCredentialRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/settings/signing/profiles/7/credentials/certificate', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAccountantCanUpdateOwnSigningProfileCredentialRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/signing/profiles/7/credentials/certificate', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAccountantCannotMutateGlobalSigningSettingsRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/signing', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAccountantCannotMutatePdfOutputSettingsRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/pdf-signing/output-settings/invoice', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAccountantCanMutateOwnPdfSigningUserDefaultRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/pdf-signing/user-defaults/invoice', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testReadonlyCannotMutateSigningProfilesRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/settings/signing/profiles', 'readonly'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReadonlyCannotMutatePdfSigningUserDefaultRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/pdf-signing/user-defaults/invoice', 'readonly'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Měsíční export je „čtení" (readonly = čtení + export) — workflow background
     * jobu (start/cancel/delete) musí projít pro všechny role, action má vlastní guard.
     */
    public function testAllRolesCanRunMonthlyExportWorkflow(): void
    {
        foreach (['readonly', 'accountant', 'admin'] as $role) {
            foreach ([
                ['POST', '/api/reports/monthly-export/start'],
                ['POST', '/api/reports/monthly-export/jobs/42/cancel'],
                ['DELETE', '/api/reports/monthly-export/jobs/42'],
            ] as [$method, $path]) {
                $response = $this->middleware()->process(
                    $this->request($method, $path, $role),
                    $this->okHandler(),
                );
                self::assertSame(204, $response->getStatusCode(), "$role $method $path");
            }
        }
    }

    public function testReadonlyCannotMutateOutsideMonthlyExport(): void
    {
        // Pojistka, že nová pravidla neotevřela víc, než měla — sousední
        // reports endpointy i jiné POSTy zůstávají pro readonly zavřené.
        foreach ([
            ['POST', '/api/reports/monthly-export/jobs/42/restart'],   // neexistující sub-akce
            ['DELETE', '/api/reports/submissions/42'],                 // mazání EPO archivu = mutace
            ['POST', '/api/invoices/1/send'],
            ['POST', '/api/clients'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->request($method, $path, 'readonly'),
                $this->okHandler(),
            );
            self::assertSame(403, $response->getStatusCode(), "readonly $method $path");
        }
    }

    /**
     * Readonly smí GET na všech datových skupinách (čtení + export je dovolené).
     */
    public function testReadonlyCanReadBusinessData(): void
    {
        foreach ([
            '/api/clients', '/api/clients/5', '/api/projects', '/api/invoices',
            '/api/invoices/5/pdf', '/api/purchase-invoices', '/api/purchase-invoices/5/our-pdf',
            '/api/recurring', '/api/bank-statements', '/api/bank-transactions/5/match-candidates',
            '/api/documents', '/api/documents/5/download', '/api/document-folders',
            '/api/suppliers', '/api/search', '/api/dashboard/summary', '/api/crm/overview',
            '/api/reports/dphkh1/preview', '/api/tax/analysis', '/api/codebooks/currencies',
            '/api/expense-categories', '/api/revenue-categories', '/api/vat-classifications',
            '/api/settings/supplier', '/api/settings/currencies', '/api/admin/export',
            '/api/admin/invoices-zip',
        ] as $path) {
            $response = $this->middleware()->process(
                $this->request('GET', $path, 'readonly'),
                $this->okHandler(),
            );
            self::assertSame(204, $response->getStatusCode(), "readonly GET $path");
        }
    }

    /**
     * Klíčová regrese: GET na admin/citlivé endpointy musí být pro non-admin
     * blokované UŽ middlewarem (ne jen guardem v Action) — pojistka proti
     * budoucímu admin GET, který zapomene vlastní kontrolu.
     */
    public function testNonAdminCannotReadAdminEndpoints(): void
    {
        foreach (['readonly', 'accountant'] as $role) {
            foreach ([
                '/api/admin/users',
                '/api/admin/activity-log',
                '/api/admin/sent-emails',
                '/api/admin/cron-jobs',
                '/api/admin/approvals',
                '/api/admin/email-templates',
                '/api/admin/email-templates/invoice_send/cs',
                '/api/admin/update/status',
                '/api/admin/smtp-log-analysis',
                '/api/admin/imports/idoklad/credentials',
                '/api/settings/bank-email-notices',
                '/api/settings/email-branding/preview',
            ] as $path) {
                $response = $this->middleware()->process(
                    $this->request('GET', $path, $role),
                    $this->okHandler(),
                );
                self::assertSame(403, $response->getStatusCode(), "$role GET $path");
            }
        }
    }

    public function testAdminCanReadAdminEndpoints(): void
    {
        $response = $this->middleware()->process(
            $this->request('GET', '/api/admin/users', 'admin'),
            $this->okHandler(),
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testAllRolesCanManageOwnPasskeys(): void
    {
        foreach (['readonly', 'accountant', 'admin'] as $role) {
            foreach ([
                ['GET', '/api/auth/webauthn/credentials'],
                ['POST', '/api/auth/webauthn/register/options'],
                ['POST', '/api/auth/webauthn/register/verify'],
                ['POST', '/api/auth/webauthn/step-up/options'],
                ['POST', '/api/auth/webauthn/step-up/verify'],
                ['POST', '/api/auth/mfa/step-up/totp'],
                ['GET', '/api/auth/session/status'],
                ['POST', '/api/auth/session/activity'],
                ['POST', '/api/auth/session/lock'],
                ['GET', '/api/auth/session/lock-preference'],
                ['PUT', '/api/auth/session/lock-preference'],
                ['POST', '/api/auth/session/unlock/options'],
                ['POST', '/api/auth/session/unlock/verify'],
                ['PATCH', '/api/auth/webauthn/credentials/42'],
                ['DELETE', '/api/auth/webauthn/credentials/42'],
            ] as [$method, $path]) {
                $response = $this->middleware()->process(
                    $this->request($method, $path, $role),
                    $this->okHandler(),
                );
                self::assertSame(204, $response->getStatusCode(), "$role $method $path");
            }
        }
    }

    /**
     * Oprava funkční mezery: účetní smí plnou CRUD na přijatých fakturách.
     */
    public function testAccountantCanMutatePurchaseInvoices(): void
    {
        foreach ([
            ['POST', '/api/purchase-invoices'],
            ['PUT', '/api/purchase-invoices/5'],
            ['PUT', '/api/purchase-invoices/5/items'],
            ['POST', '/api/purchase-invoices/5/transition'],
            ['POST', '/api/purchase-invoices/5/pdf'],
            ['DELETE', '/api/purchase-invoices/5'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->request($method, $path, 'accountant'),
                $this->okHandler(),
            );
            self::assertSame(204, $response->getStatusCode(), "accountant $method $path");
        }
    }

    public function testReadonlyCannotMutatePurchaseInvoices(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/purchase-invoices', 'readonly'),
            $this->okHandler(),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testAccountantCanMutateRecurringTemplates(): void
    {
        foreach ([
            ['POST', '/api/recurring'],
            ['PUT', '/api/recurring/5'],
            ['DELETE', '/api/recurring/5'],
            ['POST', '/api/recurring/5/pause'],
            ['POST', '/api/recurring/5/resume'],
            ['POST', '/api/recurring/5/run-now'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->request($method, $path, 'accountant'),
                $this->okHandler(),
            );
            self::assertSame(204, $response->getStatusCode(), "accountant $method $path");
        }
    }

    public function testReadonlyCannotMutateRecurringTemplates(): void
    {
        foreach ([
            ['POST', '/api/recurring'],
            ['PUT', '/api/recurring/5'],
            ['DELETE', '/api/recurring/5'],
            ['POST', '/api/recurring/5/pause'],
            ['POST', '/api/recurring/5/resume'],
            ['POST', '/api/recurring/5/run-now'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->request($method, $path, 'readonly'),
                $this->okHandler(),
            );
            self::assertSame(403, $response->getStatusCode(), "readonly $method $path");
        }
    }

    public function testAccountantCanReadImportJobStatusAndSigningSettings(): void
    {
        foreach (['/api/admin/imports/42', '/api/settings/signing'] as $path) {
            $response = $this->middleware()->process(
                $this->request('GET', $path, 'accountant'),
                $this->okHandler(),
            );
            self::assertSame(204, $response->getStatusCode(), "accountant GET $path");
        }
        // readonly na import job status nemá co dělat
        $response = $this->middleware()->process(
            $this->request('GET', '/api/admin/imports/42', 'readonly'),
            $this->okHandler(),
        );
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Import dokladů = data, ne konfigurace. Action vrstva (ImportAction,
     * Start*ImportAction, AiExtractPdfAction, Cancel/DeleteImportJobAction) povoluje
     * admin|accountant — middleware ho tam musí vůbec pustit.
     */
    public function testAccountantCanRunImports(): void
    {
        foreach ([
            ['POST', '/api/admin/import'],
            ['POST', '/api/admin/imports/idoklad/start'],
            ['POST', '/api/admin/imports/fakturoid/start'],
            ['POST', '/api/admin/imports/ai-extract-pdf'],
            ['POST', '/api/admin/imports/42/cancel'],
            ['DELETE', '/api/admin/imports/42'],
            ['GET', '/api/admin/imports/anthropic/credentials'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->request($method, $path, 'accountant'),
                $this->okHandler(),
            );
            self::assertSame(204, $response->getStatusCode(), "accountant $method $path");
        }
    }

    /** Konfigurace integrací (API klíče) zůstává admin-only. */
    public function testAccountantCannotManageIntegrationCredentials(): void
    {
        foreach ([
            ['PUT', '/api/admin/imports/idoklad/credentials'],
            ['DELETE', '/api/admin/imports/idoklad/credentials'],
            ['PUT', '/api/admin/imports/fakturoid/credentials'],
            ['DELETE', '/api/admin/imports/fakturoid/credentials'],
            ['PUT', '/api/admin/imports/anthropic/credentials'],
            ['DELETE', '/api/admin/imports/anthropic/credentials'],
            ['GET', '/api/admin/imports/idoklad/credentials'],
            ['GET', '/api/admin/imports/fakturoid/credentials'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->request($method, $path, 'accountant'),
                $this->okHandler(),
            );
            self::assertSame(403, $response->getStatusCode(), "accountant $method $path");
        }
    }

    public function testReadonlyCannotRunImports(): void
    {
        foreach ([
            ['POST', '/api/admin/import'],
            ['POST', '/api/admin/imports/idoklad/start'],
            ['POST', '/api/admin/imports/fakturoid/start'],
            ['POST', '/api/admin/imports/ai-extract-pdf'],
            ['POST', '/api/admin/imports/42/cancel'],
            ['DELETE', '/api/admin/imports/42'],
            ['GET', '/api/admin/imports/anthropic/credentials'],
        ] as [$method, $path]) {
            $response = $this->middleware()->process(
                $this->request($method, $path, 'readonly'),
                $this->okHandler(),
            );
            self::assertSame(403, $response->getStatusCode(), "readonly $method $path");
        }
    }

    public function testSupplierOwnerGetsBusinessWritesWithoutPlatformAdminFallback(): void
    {
        $middleware = $this->middlewareWithOverride('supplier_owner');

        self::assertSame(204, $middleware->process(
            $this->request('POST', '/api/clients', 'readonly'),
            $this->okHandler(),
        )->getStatusCode());
        self::assertSame(403, $middleware->process(
            $this->request('GET', '/api/admin/users', 'readonly'),
            $this->okHandler(),
        )->getStatusCode());
    }

    public function testSupplierOwnerKeepsPlatformAndSupplierRolesSeparateInRequest(): void
    {
        $response = $this->middlewareWithOverride('supplier_owner')->process(
            $this->request('GET', '/api/auth/me', 'readonly'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
                    return (new ResponseFactory())->createResponse(204)
                        ->withHeader('X-Test-Role', (string) ($user['role'] ?? ''))
                        ->withHeader('X-Test-Platform-Role', (string) ($user['platform_role'] ?? ''))
                        ->withHeader('X-Test-Supplier-Role', (string) ($user['supplier_role'] ?? ''));
                }
            },
        );

        self::assertSame('accountant', $response->getHeaderLine('X-Test-Role'));
        self::assertSame('readonly', $response->getHeaderLine('X-Test-Platform-Role'));
        self::assertSame('supplier_owner', $response->getHeaderLine('X-Test-Supplier-Role'));
    }

    private function middleware(): RoleMiddleware
    {
        // Bez membershipu = žádný per-supplier override (BC větev resolveru).
        $resolver = $this->createStub(\MyInvoice\Service\Tenant\SupplierAccessResolver::class);
        $resolver->method('resolve')->willReturn(
            new \MyInvoice\Service\Tenant\SupplierAccess(0, false, null),
        );
        return new RoleMiddleware(new ResponseFactory(), $resolver);
    }

    private function middlewareWithOverride(string $role): RoleMiddleware
    {
        $resolver = $this->createStub(\MyInvoice\Service\Tenant\SupplierAccessResolver::class);
        $resolver->method('resolve')->willReturn(
            new \MyInvoice\Service\Tenant\SupplierAccess(7, false, $role),
        );
        return new RoleMiddleware(new ResponseFactory(), $resolver);
    }

    private function request(string $method, string $path, string $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role' => $role,
            ]);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
