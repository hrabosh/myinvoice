<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Membership uživatel ↔ supplier (user_suppliers, migrace 0148) + per-supplier role.
 *
 * In-process testy: request jde přes CELOU middleware pipeline
 * (Bootstrap::buildApp() → $app->handle()) bez HTTP serveru. Autentizace přes
 * bearer PAT (user_id bez supplier bindingu → membership se vynucuje stejně
 * jako u session), pro /api/admin/* přes session (bearer tam nesmí).
 *
 * Fixtures: vlastní test useři (unikátní e-maily), druhý supplier se založí
 * pokud v DB není (šablonou podle prvního — FK na currencies/countries se
 * půjčí od supplier A). Po sobě vše uklízí.
 */
#[Group('integration')]
final class SupplierMembershipTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private ApiTokenService $svc;
    private SessionManager $sessions;
    private ?App $app = null;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private bool $createdSupplierB = false;
    private int $currencyB = 0;

    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $tokenIds = [];
    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = $this->app()->getContainer();
            $this->config   = $container->get(Config::class);
            $this->db       = $container->get(Connection::class);
            $this->svc      = $container->get(ApiTokenService::class);
            $this->sessions = $container->get(SessionManager::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        // Migrace 0148 musí být aplikovaná
        $has = $this->db->pdo()->query("SHOW TABLES LIKE 'user_suppliers'")->fetchColumn();
        if ($has === false) {
            $this->markTestSkipped('Tabulka user_suppliers chybí — spusť api/bin/migrate.php.');
        }

        $this->supplierA = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($this->supplierA <= 0) {
            $this->markTestSkipped('Žádný supplier v DB.');
        }

        // Druhý supplier — vezmi existující, nebo založ fixture (FK hodnoty od A)
        $second = $this->db->pdo()->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1 OFFSET 1'
        )->fetchColumn();
        if ($second !== false && $second !== null) {
            $this->supplierB = (int) $second;
        } else {
            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO supplier (company_name, display_name, street, city, zip, country_id,
                                       is_vat_payer, email, default_currency_id, default_vat_rate_id)
                 SELECT '__TEST membership supplier B', '__TEST membership supplier B', street, city, zip, country_id,
                        0, email, default_currency_id, default_vat_rate_id
                   FROM supplier WHERE id = ?"
            );
            $stmt->execute([$this->supplierA]);
            $this->supplierB = (int) $this->db->pdo()->lastInsertId();
            $this->createdSupplierB = true;

            // Currencies jsou per-supplier — bez CZK řádku by create klienta pod B
            // spadl na "Currency not found". default_currency_id supplier-a B záměrně
            // ukazuje na měnu A (vyhneme se cyklickému FK při úklidu).
            $cur = $this->db->pdo()->prepare(
                "INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, 'CZK', 'CZK — test', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)"
            );
            $cur->execute([$this->supplierB]);
            $this->currencyB = (int) $this->db->pdo()->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();

        foreach ($this->sessionTokens as $token) {
            try {
                $this->sessions->destroy($token);
            } catch (\Throwable) {
                // best-effort úklid
            }
        }

        if ($this->clientIds !== []) {
            $place = implode(',', array_fill(0, count($this->clientIds), '?'));
            $pdo->prepare("DELETE FROM clients WHERE id IN ($place)")->execute($this->clientIds);
        }
        if ($this->userIds !== []) {
            $place = implode(',', array_fill(0, count($this->userIds), '?'));
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM api_tokens WHERE user_id IN ($place)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($place)")->execute($this->userIds);
        }
        if ($this->createdSupplierB && $this->supplierB > 0) {
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierB]);
            if ($this->currencyB > 0) {
                $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$this->currencyB]);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierB]);
        }

        $this->userIds = $this->tokenIds = $this->clientIds = $this->sessionTokens = [];
        $this->db->close();
        $this->app = null;
    }

    // ---------------------------------------------------------------- tests

    public function testMembershipBlocksForeignSupplierAndAllowsOwn(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $token = $this->mkToken($userId);

        // Cizí firma → 403 forbidden_supplier
        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(403, $res->getStatusCode(), 'Membership {A} musí blokovat X-Supplier-Id: B');
        $body = $this->json($res);
        self::assertSame('forbidden_supplier', $body['error']['code'] ?? null);

        // Vlastní firma → 200 + správné id
        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $body = $this->json($res);
        self::assertSame($this->supplierA, $body['id'] ?? null);
    }

    public function testMembershipFallbackWithoutHeaderLandsOnAssignedSupplier(): void
    {
        // Membership {B} bez X-Supplier-Id → fallback musí jít na B (přiřazenou),
        // ne na globální MIN(id) = A.
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierB, null]]);
        $token = $this->mkToken($userId);

        $res = $this->request('GET', '/api/settings/supplier', $token, null);
        self::assertSame(200, $res->getStatusCode());
        $body = $this->json($res);
        self::assertSame($this->supplierB, $body['id'] ?? null,
            'Fallback bez headeru musí zvolit přiřazený supplier, ne globální MIN');
    }

    public function testUserWithoutMembershipKeepsFullAccess(): void
    {
        // BC: žádné řádky v user_suppliers = dosavadní chování (vidí vše)
        $userId = $this->mkUser('accountant');
        $token = $this->mkToken($userId);

        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(200, $res->getStatusCode());
        $body = $this->json($res);
        self::assertSame($this->supplierB, $body['id'] ?? null);
    }

    public function testSuppliersListFilteredByMembership(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $token = $this->mkToken($userId);

        $res = $this->request('GET', '/api/suppliers', $token, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertSame([$this->supplierA], $ids, 'Uživatel s membership {A} smí vidět jen firmu A');

        // Detail cizí firmy neleakuje existenci → 404
        $res = $this->request('GET', '/api/suppliers/' . $this->supplierB, $token, $this->supplierA);
        self::assertSame(404, $res->getStatusCode());

        // Admin bez membership vidí plný seznam (vč. B)
        $adminId = $this->mkUser('admin');
        $adminToken = $this->mkToken($adminId);
        $res = $this->request('GET', '/api/suppliers', $adminToken, $this->supplierA);
        self::assertSame(200, $res->getStatusCode());
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertContains($this->supplierA, $ids);
        self::assertContains($this->supplierB, $ids);
    }

    public function testMeReturnsOnlyAssignedSuppliers(): void
    {
        // Přepínač firem v SPA se plní z /api/auth/me → smí nabídnout jen přiřazené.
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $session = $this->mkSession($userId);

        $res = $this->sessionRequest('GET', '/api/auth/me', $session);
        self::assertSame(200, $res->getStatusCode());
        $body = $this->json($res);
        $ids = array_map(fn ($r) => (int) $r['id'], $body['suppliers'] ?? []);
        self::assertSame([$this->supplierA], $ids);
        self::assertSame($this->supplierA, (int) ($body['current_supplier_id'] ?? 0));
    }

    public function testReadonlyOverrideBlocksWriteDespiteGlobalAccountant(): void
    {
        // Globální accountant, ale pro firmu A override 'readonly' → POST blokován;
        // pro firmu B (override NULL = zdědit accountant) POST projde.
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, 'readonly'], [$this->supplierB, null]]);
        $token = $this->mkToken($userId);

        $payload = [
            'company_name' => '__TEST membership client',
            'street' => 'Testovací 1', 'city' => 'Praha', 'zip' => '11000',
            'main_email' => 'membership-test-client@example.com',
        ];

        $res = $this->request('POST', '/api/clients', $token, $this->supplierA, $payload);
        self::assertSame(403, $res->getStatusCode(),
            'Per-supplier readonly override musí blokovat mutaci i pro globálního accountanta');
        $body = $this->json($res);
        self::assertSame('forbidden', $body['error']['code'] ?? null);

        $res = $this->request('POST', '/api/clients', $token, $this->supplierB, $payload);
        self::assertSame(201, $res->getStatusCode(),
            'Bez override (NULL) platí globální accountant → create musí projít');
        $body = $this->json($res);
        if (isset($body['id'])) {
            $this->clientIds[] = (int) $body['id'];
            // Klient musí vzniknout pod firmou B (scope z headeru)
            self::assertSame($this->supplierB, (int) ($body['supplier_id'] ?? 0));
        }
    }

    public function testAssignmentsEndpointAdminOnlyAndReplacesSet(): void
    {
        // /api/admin/* je pro bearer PAT zakázané (ApiScopeMiddleware path
        // allowlist) → testujeme session autentizací (cookie + CSRF).
        $accountantId = $this->mkUser('accountant');
        $accountantSession = $this->mkSession($accountantId);
        $adminId = $this->mkUser('admin');
        $adminSession = $this->mkSession($adminId);

        // Non-admin → 403 (RoleMiddleware admin-only fallback pro /api/admin/*)
        $res = $this->sessionRequest('GET', "/api/admin/users/$accountantId/suppliers", $accountantSession);
        self::assertSame(403, $res->getStatusCode());
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $accountantSession,
            ['assignments' => [['supplier_id' => $this->supplierA, 'role' => null]]]);
        self::assertSame(403, $res->getStatusCode());

        // Admin: PUT replace + GET
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession, [
            'assignments' => [
                ['supplier_id' => $this->supplierA, 'role' => 'supplier_owner'],
                ['supplier_id' => $this->supplierB, 'role' => null],
            ],
        ]);
        self::assertSame(200, $res->getStatusCode());
        $list = $this->json($res);
        self::assertCount(2, $list);
        self::assertSame($this->supplierA, $list[0]['supplier_id']);
        self::assertSame('supplier_owner', $list[0]['role']);
        self::assertSame($this->supplierB, $list[1]['supplier_id']);
        self::assertNull($list[1]['role']);
        self::assertNotSame('', (string) ($list[0]['name'] ?? ''));

        $res = $this->sessionRequest('GET', "/api/admin/users/$accountantId/suppliers", $adminSession);
        self::assertSame(200, $res->getStatusCode());
        self::assertCount(2, $this->json($res));

        // Replace na jediné přiřazení — sada se NAHRAZUJE, ne přidává
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession, [
            'assignments' => [['supplier_id' => $this->supplierB, 'role' => 'accountant']],
        ]);
        self::assertSame(200, $res->getStatusCode());
        $list = $this->json($res);
        self::assertCount(1, $list);
        self::assertSame($this->supplierB, $list[0]['supplier_id']);
        self::assertSame('accountant', $list[0]['role']);

        // Validace: neexistující supplier → 400; neplatná role → 400
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession,
            ['assignments' => [['supplier_id' => 999999999, 'role' => null]]]);
        self::assertSame(400, $res->getStatusCode());
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession,
            ['assignments' => [['supplier_id' => $this->supplierA, 'role' => 'admin']]]);
        self::assertSame(400, $res->getStatusCode(),
            "Override 'admin' nesmí projít — eskalace na globálního admina");

        // Prázdná sada = odebrání omezení (zpět do BC režimu)
        $res = $this->sessionRequest('PUT', "/api/admin/users/$accountantId/suppliers", $adminSession,
            ['assignments' => []]);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $this->json($res));
    }

    public function testSupplierOwnerManagesOnlyCurrentSupplierMembersAndRevokesSession(): void
    {
        $ownerId = $this->mkUser('readonly');
        $memberId = $this->mkUser('accountant');
        $foreignId = $this->mkUser('accountant');
        $this->assign($ownerId, [[$this->supplierA, 'supplier_owner']]);
        $this->assign($memberId, [[$this->supplierA, 'accountant']]);
        $this->assign($foreignId, [[$this->supplierB, 'accountant']]);
        $ownerSession = $this->mkSession($ownerId);
        $memberSession = $this->mkSession($memberId);

        $res = $this->sessionRequest('GET', '/api/settings/supplier/members', $ownerSession);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $ids = array_map(static fn (array $row): int => (int) $row['user_id'], $this->json($res));
        self::assertContains($ownerId, $ids);
        self::assertContains($memberId, $ids);
        self::assertNotContains($foreignId, $ids, 'Owner nesmí vidět člena jiné firmy');

        $res = $this->sessionRequest(
            'PUT',
            "/api/settings/supplier/members/$memberId",
            $ownerSession,
            ['role' => 'readonly'],
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $stmt = $this->db->pdo()->prepare(
            'SELECT role FROM user_suppliers WHERE supplier_id = ? AND user_id = ?'
        );
        $stmt->execute([$this->supplierA, $memberId]);
        self::assertSame('readonly', $stmt->fetchColumn());
        $sessionStmt = $this->db->pdo()->prepare('SELECT revoked_at FROM sessions WHERE id = ?');
        $sessionStmt->execute([$memberSession['token']]);
        self::assertNotNull($sessionStmt->fetchColumn(), 'Změna role musí odvolat existující session člena');

        $res = $this->sessionRequest(
            'DELETE',
            "/api/settings/supplier/members/$foreignId",
            $ownerSession,
        );
        self::assertSame(404, $res->getStatusCode(), 'Cizí membership se nesmí změnit ani přes známé user id');

        $res = $this->sessionRequest(
            'DELETE',
            "/api/settings/supplier/members/$memberId",
            $ownerSession,
        );
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $stmt->execute([$this->supplierA, $memberId]);
        self::assertFalse($stmt->fetchColumn());

        $res = $this->sessionRequest(
            'DELETE',
            "/api/settings/supplier/members/$ownerId",
            $ownerSession,
        );
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('self_membership_change_forbidden', $this->json($res)['error']['code'] ?? null);
    }

    public function testGlobalAdminExemptFromMembership(): void
    {
        // Globální admin s membership řádky {A} NESMÍ být zamčen — vidí i firmu B
        // (admin je z membershipu vyjmut, jinak by šla instalace „vyzamknout").
        $adminId = $this->mkUser('admin');
        $this->assign($adminId, [[$this->supplierA, null]]);
        $token = $this->mkToken($adminId);

        $res = $this->request('GET', '/api/settings/supplier', $token, $this->supplierB);
        self::assertSame(200, $res->getStatusCode(),
            'Globální admin s membershipem musí i tak vidět firmu mimo něj');
        self::assertSame($this->supplierB, $this->json($res)['id'] ?? null);

        // A přepínač firem mu ukáže obě (ne jen přiřazenou A)
        $res = $this->request('GET', '/api/suppliers', $token, $this->supplierA);
        $ids = array_map(fn ($r) => (int) $r['id'], $this->json($res));
        self::assertContains($this->supplierB, $ids, 'Admin vidí plný seznam firem i s membershipem');
    }

    public function testBoundTokenOutsideMembershipDenied(): void
    {
        // Non-admin s membershipem {A} a PAT bound na firmu B (mimo membership)
        // → 403; bound token nesmí membership obejít.
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null]]);
        $bound = $this->mkBoundToken($userId, $this->supplierB);

        $res = $this->request('GET', '/api/settings/supplier', $bound, null);
        self::assertSame(403, $res->getStatusCode(),
            'Bound PAT na firmu mimo membership musí být odmítnut');
        self::assertSame('forbidden_supplier', $this->json($res)['error']['code'] ?? null);
    }

    public function testTokenCreationRejectsSupplierOutsideMembership(): void
    {
        // Uživatel s explicitním membershipem {A} nesmí vytvořit token bound na
        // firmu B. Správu tokenů (POST /api/auth/tokens) pouští RoleMiddleware jen
        // adminovi, proto testujeme adminem, kterému membership {A} explicitně zúží
        // i mintování tokenů.
        $userId = $this->mkUser('admin');
        $this->assign($userId, [[$this->supplierA, null]]);
        $session = $this->mkSession($userId);

        $res = $this->sessionRequest('POST', '/api/auth/tokens', $session, [
            'name' => '__test_membership_bound_B', 'supplier_id' => $this->supplierB, 'scope' => 'read',
        ]);
        self::assertSame(403, $res->getStatusCode(),
            'Token bound na firmu mimo membership nesmí jít vytvořit');
        self::assertSame('forbidden_supplier', $this->json($res)['error']['code'] ?? null);

        // Tentýž token na přiřazenou firmu A → 201
        $res = $this->sessionRequest('POST', '/api/auth/tokens', $session, [
            'name' => '__test_membership_bound_A', 'supplier_id' => $this->supplierA, 'scope' => 'read',
        ]);
        self::assertSame(201, $res->getStatusCode(),
            'Token na přiřazenou firmu se musí vytvořit');
        $id = $this->json($res)['id'] ?? null;
        if ($id !== null) $this->tokenIds[] = (int) $id;
    }

    public function testDeletingSupplierCleansUpAssignments(): void
    {
        // DELETE FROM supplier běží s FOREIGN_KEY_CHECKS = 0 → ON DELETE CASCADE
        // se neprovede a přiřazení je nutné uklidit explicitně. Jinak by omezenému
        // uživateli zůstal v setu neexistující dodavatel.
        if (!$this->createdSupplierB) {
            self::markTestSkipped('Supplier B je reálná firma z DB — mazat ho nebudeme.');
        }
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [[$this->supplierA, null], [$this->supplierB, null]]);
        $adminSession = $this->mkSession($this->mkUser('admin'));

        $res = $this->sessionRequest('DELETE', '/api/suppliers/' . $this->supplierB, $adminSession);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->createdSupplierB = false;
        $this->currencyB = 0;

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM user_suppliers WHERE supplier_id = ?');
        $stmt->execute([$this->supplierB]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Přiřazení smazané firmy musí zmizet');
    }

    // ------------------------------------------------------------- fixtures

    private function mkUser(string $role): int
    {
        $email = '__test_membership_' . bin2hex(random_bytes(6)) . '@example.com';
        // users.password_hash je CHAR(60) — hash generujeme, ať má vždy přesnou
        // délku bcryptu. Literál o znak delší projde jen na serveru bez STRICT
        // režimu (lokálně), v CI spadne na 1406 Data too long. Heslem se stejně
        // nikdo nepřihlašuje, session i tokeny vytváříme přímo.
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, \'__TEST membership\', ?, \'cs\', 1)'
        );
        $stmt->execute([$email, password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $role]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    /** @param list<array{0:int,1:?string}> $rows [[supplier_id, role|null], …] */
    private function assign(int $userId, array $rows): void
    {
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO user_suppliers (user_id, supplier_id, role) VALUES (?, ?, ?)'
        );
        foreach ($rows as [$sid, $role]) {
            $ins->execute([$userId, $sid, $role]);
        }
    }

    private function mkToken(int $userId): string
    {
        $out = $this->svc->generate($userId, null, '__test_membership_' . bin2hex(random_bytes(4)), 'read_write', null);
        $this->tokenIds[] = (int) $out['id'];
        return $out['plaintext'];
    }

    /** PAT bound na konkrétní supplier (obchází header/query — testuje membership u bound tokenu). */
    private function mkBoundToken(int $userId, int $supplierId): string
    {
        $out = $this->svc->generate($userId, $supplierId, '__test_membership_bound_' . bin2hex(random_bytes(4)), 'read_write', null);
        $this->tokenIds[] = (int) $out['id'];
        return $out['plaintext'];
    }

    /**
     * Session se strong assurance — instalace s `auth.require_mfa` by legacy
     * session odmítla (`mfa_reauthentication_required`) ještě před RBAC.
     *
     * @return array{token:string, csrf_token:string}
     */
    private function mkSession(int $userId): array
    {
        $out = $this->sessions->create(
            $userId,
            '127.0.0.1',
            '__test_membership',
            SessionAuthContext::strong('password', new \DateTimeImmutable()),
        );
        $this->sessionTokens[] = (string) $out['token'];
        return ['token' => $out['token'], 'csrf_token' => $out['csrf_token']];
    }

    // -------------------------------------------------------------- helpers

    private function app(): App
    {
        return $this->app ??= Bootstrap::buildApp();
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function request(
        string $method,
        string $path,
        string $bearer,
        ?int $supplierId,
        ?array $body = null,
    ): ResponseInterface {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $bearer);
        if ($supplierId !== null) {
            $req = $req->withHeader('X-Supplier-Id', (string) $supplierId);
        }
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    /**
     * Session-based request (cookie + Origin + X-CSRF-Token) — nutné pro
     * /api/admin/* endpointy, které jsou pro bearer PAT zakázané.
     *
     * @param array{token:string, csrf_token:string} $session
     * @param array<string,mixed>|null $body
     */
    private function sessionRequest(
        string $method,
        string $path,
        array $session,
        ?array $body = null,
    ): ResponseInterface {
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', $appUrl)
            ->withHeader('X-CSRF-Token', $session['csrf_token']);
        if ($body !== null) {
            $stream = (new StreamFactory())->createStream(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            );
            $req = $req->withHeader('Content-Type', 'application/json')->withBody($stream);
        }
        return $this->app()->handle($req);
    }

    /** @return array<mixed> */
    private function json(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
