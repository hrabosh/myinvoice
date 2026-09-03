<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftService;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorReturnUrlPolicy;
use MyInvoice\Service\Integration\Revizior\ReviziorSsoService;
use MyInvoice\Service\Integration\Revizior\ReviziorSsoTargetPolicy;
use MyInvoice\Service\Revizior\Security\ReviziorReplayGuard;
use MyInvoice\Service\Revizior\Security\ReviziorSsoException;
use MyInvoice\Service\Revizior\Security\ReviziorSsoTicketVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * SSO nad skutečnou DB: session vzniká, ticket platí jednou, cizí cíl
 * a odebrané členství neprojdou.
 */
#[Group('integration')]
final class SsoTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000004';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000031';
    private const OWNER_EMAIL = 'revizior-sso-owner@example.invalid';
    private const CLIENT_UUID = '40000000-0000-4000-8000-000000000001';
    private const INVOICE_KEY = '60000000-0000-4000-8000-000000000001';
    private const ISSUER = 'https://app.revizior.cz';
    private const AUDIENCE = 'https://fakturace.revizior.cz/api/auth/revizior/sso';
    private const KEY_ID = 'revizior-sso-test';

    private Connection $db;
    private ReviziorSsoService $sso;
    private int $supplierId;
    private int $invoiceId;
    private int $clientId;
    private string $privateKeyPath;
    private string $publicKeyPath;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $clients = $container->get(ReviziorClientSynchronizer::class);
            $drafts = $container->get(ReviziorInvoiceDraftService::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->db->pdo()->query("SHOW COLUMNS FROM sessions LIKE 'revizior_return_url'")->fetchColumn() === false) {
            $this->markTestSkipped('Migrace 0156_revizior_sso_session_return.sql chybí.');
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $this->privateKeyPath = (string) tempnam(sys_get_temp_dir(), 'sso-priv');
        $this->publicKeyPath = (string) tempnam(sys_get_temp_dir(), 'sso-pub');
        file_put_contents($this->privateKeyPath, (string) $privatePem);
        file_put_contents($this->publicKeyPath, (string) openssl_pkey_get_details($key)['key']);

        $this->cleanup();
        $provisioned = $provisioner->provision(self::ORGANIZATION_UUID, $this->provisionBody(), 'provision:' . self::ORGANIZATION_UUID);
        $this->supplierId = (int) $provisioned->data['supplierId'];
        $client = $clients->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $this->fixture('client-upsert-request'));
        $this->clientId = (int) $client->data['externalClientId'];
        $draft = $drafts->create(self::ORGANIZATION_UUID, $this->fixture('invoice-draft-request'), 'invoice-draft:' . self::INVOICE_KEY);
        $this->invoiceId = (int) $draft->data['invoiceId'];

        $config = new Config([
            'app' => ['env' => 'development'],
            'session' => ['cookie_name' => 'myinvoice_session', 'cookie_secure' => false, 'cookie_samesite' => 'Lax', 'lifetime_days' => 30],
            'deployment' => [
                'revizior' => [
                    'app_url' => 'https://app.revizior.cz/fakturace',
                    'allowed_return_hosts' => ['app.revizior.cz'],
                    'service_auth' => ['issuer' => self::ISSUER, 'clock_skew_seconds' => 5],
                    'sso' => ['audience' => self::AUDIENCE, 'key_id' => self::KEY_ID, 'public_key_path' => $this->publicKeyPath],
                ],
            ],
        ]);
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
        $this->sso = new ReviziorSsoService(
            $this->db,
            new ReviziorSsoTicketVerifier($config, $clock),
            new ReviziorReplayGuard($this->db),
            new ReviziorSsoTargetPolicy(),
            new ReviziorReturnUrlPolicy($config),
            $container->get(SessionManager::class),
            $container->get(ActivityLogger::class),
            $config,
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->privateKeyPath ?? null, $this->publicKeyPath ?? null] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testTicketCreatesSessionOnceAndStoresTheApprovedReturnUrl(): void
    {
        $result = $this->sso->consume($this->ticket(), '127.0.0.1', 'PHPUnit');

        self::assertSame('/invoices/' . $this->invoiceId . '/edit', $result['redirect']);
        $session = $this->row(
            'SELECT user_id, auth_method, assurance_level, revizior_return_url FROM sessions WHERE id = ?',
            [$result['session']['token']],
        );
        self::assertSame('revizior_sso', $session['auth_method']);
        self::assertSame('basic', $session['assurance_level']);
        self::assertSame('https://app.revizior.cz/faktury', $session['revizior_return_url']);
        $owner = $this->row('SELECT user_id FROM revizior_user_links rul JOIN revizior_organization_links rol ON rol.id = rul.organization_link_id WHERE rol.organization_uuid = ?', [self::ORGANIZATION_UUID]);
        self::assertSame((int) $owner['user_id'], (int) $session['user_id']);

        $cookie = $this->sso->cookieHeader($result['session']['token'], (int) $result['session']['expires_at']);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);

        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = 'revizior.sso.consumed'", [$this->supplierId]));
    }

    public function testSecondUseOfTheSameTicketFails(): void
    {
        $ticket = $this->ticket();
        $this->sso->consume($ticket, '127.0.0.1', 'PHPUnit');

        try {
            $this->sso->consume($ticket, '127.0.0.1', 'PHPUnit');
            self::fail('replay must fail');
        } catch (ReviziorSsoException $e) {
            self::assertSame('sso_ticket_replayed', $e->errorCode);
        }
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM sessions WHERE auth_method = ?', ['revizior_sso']));
    }

    public function testForeignTargetsAndUnavailableScreensAreRejected(): void
    {
        foreach ([
            '/invoices/999999/edit' => 'sso_target_forbidden',
            '/invoices/1/../../etc' => 'sso_target_forbidden',
            '/admin/settings' => 'sso_target_forbidden',
        ] as $target => $expected) {
            try {
                $this->sso->consume($this->ticket(['target' => $target]), '127.0.0.1', 'PHPUnit');
                self::fail('expected rejection of ' . $target);
            } catch (ReviziorSsoException $e) {
                self::assertSame($expected, $e->errorCode, $target);
            }
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM sessions WHERE auth_method = ?', ['revizior_sso']));
    }

    public function testClientAndSettingsTargetsAreAllowedAndForeignReturnUrlIsRejected(): void
    {
        $result = $this->sso->consume($this->ticket(['target' => '/clients/' . $this->clientId]), '127.0.0.1', 'PHPUnit');
        self::assertSame('/clients/' . $this->clientId, $result['redirect']);

        // Tenantová obrazovka fakturační identity existuje od R6.
        $settings = $this->sso->consume($this->ticket(['target' => '/settings/supplier']), '127.0.0.1', 'PHPUnit');
        self::assertSame('/settings/supplier', $settings['redirect']);

        // Ceník je v kontraktu `/price-list`, v aplikaci `/admin/price-list`.
        $priceList = $this->sso->consume($this->ticket(['target' => '/price-list']), '127.0.0.1', 'PHPUnit');
        self::assertSame('/admin/price-list', $priceList['redirect']);

        try {
            $this->sso->consume($this->ticket(['return_to' => 'https://evil.example/faktury']), '127.0.0.1', 'PHPUnit');
            self::fail('foreign return url must fail');
        } catch (ReviziorSsoException $e) {
            self::assertSame('sso_return_url_forbidden', $e->errorCode);
        }
    }

    public function testRevokedMembershipAndSuspendedOrganizationCannotEnter(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE revizior_user_links rul JOIN revizior_organization_links rol ON rol.id = rul.organization_link_id
                SET rul.active = 0, rul.revoked_at = UTC_TIMESTAMP(6)
              WHERE rol.organization_uuid = ?'
        )->execute([self::ORGANIZATION_UUID]);
        try {
            $this->sso->consume($this->ticket(), '127.0.0.1', 'PHPUnit');
            self::fail('revoked membership must fail');
        } catch (ReviziorSsoException $e) {
            self::assertSame('sso_membership_inactive', $e->errorCode);
        }

        $this->db->pdo()->prepare(
            'UPDATE revizior_user_links rul JOIN revizior_organization_links rol ON rol.id = rul.organization_link_id
                SET rul.active = 1, rul.revoked_at = NULL WHERE rol.organization_uuid = ?'
        )->execute([self::ORGANIZATION_UUID]);
        $this->db->pdo()->prepare("UPDATE revizior_organization_links SET status = 'suspended' WHERE organization_uuid = ?")
            ->execute([self::ORGANIZATION_UUID]);
        try {
            $this->sso->consume($this->ticket(), '127.0.0.1', 'PHPUnit');
            self::fail('suspended organization must fail');
        } catch (ReviziorSsoException $e) {
            self::assertSame('sso_organization_suspended', $e->errorCode);
        }
    }

    /** @param array<string,mixed> $overrides */
    private function ticket(array $overrides = []): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => self::OWNER_UUID,
            'organization_id' => self::ORGANIZATION_UUID,
            'jti' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 60,
            'purpose' => 'browser_sso',
            'target' => '/invoices/' . $this->invoiceId . '/edit',
            'return_to' => 'https://app.revizior.cz/faktury',
        ], $overrides);

        $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))
            ->create()
            ->withPayload((string) json_encode($claims, JSON_THROW_ON_ERROR))
            ->addSignature(JWKFactory::createFromKeyFile($this->privateKeyPath), ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KEY_ID])
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Řádek nenalezen: ' . $sql);
        return $row;
    }

    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . "/source/revizior-integration/contract/v1/{$name}.json"),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,mixed> */
    private function provisionBody(): array
    {
        $body = $this->fixture('provision-request');
        $body['owner']['userUuid'] = self::OWNER_UUID;
        $body['owner']['email'] = self::OWNER_EMAIL;
        return $body;
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare('SELECT id, supplier_id FROM revizior_organization_links WHERE organization_uuid = ?');
        $statement->execute([self::ORGANIZATION_UUID]);
        $org = $statement->fetch(\PDO::FETCH_ASSOC);
        $pdo->prepare('DELETE FROM revizior_idempotency_keys WHERE subject_uuid = ?')->execute([self::ORGANIZATION_UUID]);
        $pdo->prepare("DELETE FROM revizior_security_nonces WHERE purpose = 'sso'")->execute();
        if (!is_array($org)) {
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::OWNER_EMAIL]);
            return;
        }
        $orgLinkId = (int) $org['id'];
        $supplierId = (int) $org['supplier_id'];
        $users = $pdo->prepare('SELECT user_id FROM revizior_user_links WHERE organization_link_id = ?');
        $users->execute([$orgLinkId]);
        $userIds = array_map('intval', $users->fetchAll(\PDO::FETCH_COLUMN));

        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_event_outbox WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE s FROM revizior_invoice_sources s JOIN revizior_invoice_links l ON l.id = s.invoice_link_id WHERE l.organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM revizior_invoice_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_client_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE cec FROM client_email_contacts cec JOIN clients c ON c.id = cec.client_id WHERE c.supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_user_links WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM user_suppliers WHERE supplier_id = ?')->execute([$supplierId]);
        $pdo->prepare('DELETE FROM revizior_organization_links WHERE id = ?')->execute([$orgLinkId]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplierId]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::OWNER_EMAIL]);
    }
}
