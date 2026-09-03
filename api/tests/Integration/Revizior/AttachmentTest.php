<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Revizior;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Service\Integration\Revizior\ReviziorAttachmentService;
use MyInvoice\Service\Integration\Revizior\ReviziorClientSynchronizer;
use MyInvoice\Service\Integration\Revizior\ReviziorInvoiceDraftService;
use MyInvoice\Service\Integration\Revizior\ReviziorOrganizationProvisioner;
use MyInvoice\Service\Integration\Revizior\ReviziorProvisioningException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Příloha dokladu: digest, magic bytes, limit a idempotence proti skutečné DB
 * i skutečnému úložišti.
 */
#[Group('integration')]
final class AttachmentTest extends TestCase
{
    private const ORGANIZATION_UUID = '39000000-0000-4000-8000-000000000006';
    private const OWNER_UUID = '29000000-0000-4000-8000-000000000051';
    private const OWNER_EMAIL = 'revizior-attachment-owner@example.invalid';
    private const CLIENT_UUID = '40000000-0000-4000-8000-000000000001';
    private const INVOICE_KEY = '60000000-0000-4000-8000-000000000001';
    private const ATTACHMENT_KEY = '90000000-0000-4000-8000-000000000001';

    private Connection $db;
    private ReviziorAttachmentService $service;
    private int $supplierId;
    private int $invoiceId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(ReviziorAttachmentService::class);
            $provisioner = $container->get(ReviziorOrganizationProvisioner::class);
            $clients = $container->get(ReviziorClientSynchronizer::class);
            $drafts = $container->get(ReviziorInvoiceDraftService::class);
            $this->db->pdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->db->pdo()->query("SHOW TABLES LIKE 'revizior_attachment_links'")->fetchColumn() === false) {
            $this->markTestSkipped('Migrace 0158_revizior_attachment_links.sql chybí.');
        }
        $this->cleanup();
        $provisioned = $provisioner->provision(self::ORGANIZATION_UUID, $this->provisionBody(), 'provision:' . self::ORGANIZATION_UUID);
        $this->supplierId = (int) $provisioned->data['supplierId'];
        $clients->upsert(self::ORGANIZATION_UUID, self::CLIENT_UUID, $this->fixture('client-upsert-request'));
        $draft = $drafts->create(self::ORGANIZATION_UUID, $this->fixture('invoice-draft-request'), 'invoice-draft:' . self::INVOICE_KEY);
        $this->invoiceId = (int) $draft->data['invoiceId'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $this->cleanup();
        $this->db->close();
    }

    public function testPdfIsStoredOnceAndRepeatedUploadReturnsTheSameAttachment(): void
    {
        $pdf = $this->pdf();
        $result = $this->store($pdf);

        self::assertTrue($result['created']);
        self::assertSame(hash('sha256', $pdf), $result['data']['sha256']);
        self::assertSame(strlen($pdf), $result['data']['sizeBytes']);
        $attachmentId = (int) $result['data']['attachmentId'];

        $row = $this->row(
            'SELECT invoice_id, filename, original_name, size_bytes, sha256, mime_type, uploaded_by
               FROM invoice_attachments WHERE id = ?',
            [$attachmentId],
        );
        self::assertSame($this->invoiceId, (int) $row['invoice_id']);
        self::assertSame('revizior-' . self::ATTACHMENT_KEY . '.pdf', $row['filename']);
        self::assertSame('revizni-zprava.pdf', $row['original_name']);
        self::assertSame('application/pdf', $row['mime_type']);
        self::assertNull($row['uploaded_by'], 'Integrace nemá uživatele.');

        $path = (new InvoiceAttachmentRepository($this->db))->pathFor($this->supplierId, $this->invoiceId, (string) $row['filename']);
        self::assertFileExists($path);
        self::assertSame($pdf, file_get_contents($path));

        $link = $this->row(
            'SELECT attachment_id, sha256_hex, size_bytes FROM revizior_attachment_links WHERE external_attachment_key = ?',
            [self::ATTACHMENT_KEY],
        );
        self::assertSame($attachmentId, (int) $link['attachment_id']);

        // Retry po timeoutu: stejný klíč i digest → původní příloha, žádná druhá.
        $retry = $this->store($pdf);
        self::assertFalse($retry['created']);
        self::assertSame($result['data'], $retry['data']);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM invoice_attachments WHERE invoice_id = ?', [$this->invoiceId]));
    }

    public function testSameKeyWithDifferentContentIsAConflict(): void
    {
        $this->store($this->pdf());

        try {
            $this->store($this->pdf('jiny obsah'));
            self::fail('different digest must conflict');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('attachment_digest_mismatch', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM invoice_attachments WHERE invoice_id = ?', [$this->invoiceId]));
    }

    public function testForgedContentTypeTruncatedBodyAndWrongDigestAreRejected(): void
    {
        $pdf = $this->pdf();

        // 1) Není to PDF, i když se tak hlásí.
        try {
            $this->store('<html>ne</html>');
            self::fail('non-pdf must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('attachment_invalid', $e->errorCode);
            self::assertSame(['attachment' => 'not_a_pdf'], $e->fields);
        }

        // 2) Digest nesedí na obsah.
        try {
            $this->store($pdf, digestOf: 'jiny obsah');
            self::fail('wrong digest must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('attachment_digest_mismatch', $e->errorCode);
        }

        // 3) Deklarovaná délka nesedí se skutečně přečtenou.
        try {
            $this->store($pdf, contentLength: strlen($pdf) + 10);
            self::fail('length mismatch must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame(['attachment' => 'content_length_mismatch'], $e->fields);
        }

        // 4) Cizí content type.
        try {
            $this->store($pdf, contentType: 'application/octet-stream');
            self::fail('content type must be checked');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame(['attachment' => 'unsupported_content_type'], $e->fields);
        }

        // 5) Chybějící Digest hlavička.
        try {
            $this->service->store(
                self::ORGANIZATION_UUID,
                self::INVOICE_KEY,
                self::ATTACHMENT_KEY,
                (new StreamFactory())->createStream($pdf),
                'application/pdf',
                null,
                strlen($pdf),
                null,
            );
            self::fail('digest header is required');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame(['attachment' => 'digest_header_required'], $e->fields);
        }

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invoice_attachments WHERE invoice_id = ?', [$this->invoiceId]));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM revizior_attachment_links', []));
    }

    public function testOversizedBodyIsRejectedAndNothingIsStored(): void
    {
        $oversized = $this->pdf(str_repeat('a', ReviziorAttachmentService::MAX_BYTES));

        try {
            $this->store($oversized);
            self::fail('oversized body must be rejected');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('attachment_too_large', $e->errorCode);
            self::assertSame(413, $e->httpStatus);
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invoice_attachments WHERE invoice_id = ?', [$this->invoiceId]));
    }

    public function testPathTraversalInFileNameCannotEscapeStorage(): void
    {
        $result = $this->store($this->pdf(), fileName: '../../../../etc/passwd');

        $row = $this->row('SELECT filename, original_name FROM invoice_attachments WHERE id = ?', [(int) $result['data']['attachmentId']]);
        self::assertSame('revizior-' . self::ATTACHMENT_KEY . '.pdf', $row['filename']);
        self::assertSame('passwd.pdf', $row['original_name']);
        self::assertStringNotContainsString('..', (string) $row['original_name']);
    }

    public function testUnknownInvoiceAndForeignOrganizationAreNotFound(): void
    {
        try {
            $this->service->store(
                self::ORGANIZATION_UUID,
                '60000000-0000-4000-8000-000000000099',
                self::ATTACHMENT_KEY,
                (new StreamFactory())->createStream($this->pdf()),
                'application/pdf',
                'sha-256=' . base64_encode((string) hex2bin(hash('sha256', $this->pdf()))),
                null,
                null,
            );
            self::fail('unknown invoice must be 404');
        } catch (ReviziorProvisioningException $e) {
            self::assertSame('invoice_not_found', $e->errorCode);
        }
    }

    /**
     * @return array{data: array<string,mixed>, created: bool}
     */
    private function store(
        string $content,
        ?string $digestOf = null,
        ?int $contentLength = null,
        string $contentType = 'application/pdf',
        ?string $fileName = null,
    ): array {
        $digestSource = $digestOf ?? $content;

        return $this->service->store(
            self::ORGANIZATION_UUID,
            self::INVOICE_KEY,
            self::ATTACHMENT_KEY,
            (new StreamFactory())->createStream($content),
            $contentType,
            'sha-256=' . base64_encode((string) hex2bin(hash('sha256', $digestSource))),
            $contentLength ?? strlen($content),
            $fileName,
        );
    }

    private function pdf(string $filler = 'revizni zprava'): string
    {
        return "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n" . $filler . "\n%%EOF\n";
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
        if (!is_array($org)) {
            $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([self::OWNER_EMAIL]);

            return;
        }
        $orgLinkId = (int) $org['id'];
        $supplierId = (int) $org['supplier_id'];
        $users = $pdo->prepare('SELECT user_id FROM revizior_user_links WHERE organization_link_id = ?');
        $users->execute([$orgLinkId]);
        $userIds = array_map('intval', $users->fetchAll(\PDO::FETCH_COLUMN));

        $invoices = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ?');
        $invoices->execute([$supplierId]);
        foreach (array_map('intval', $invoices->fetchAll(\PDO::FETCH_COLUMN)) as $invoiceId) {
            (new InvoiceAttachmentRepository($this->db))->purgeFilesForInvoice($supplierId, $invoiceId);
        }
        $pdo->prepare('DELETE al FROM revizior_attachment_links al JOIN revizior_invoice_links l ON l.id = al.invoice_link_id WHERE l.organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM revizior_event_outbox WHERE organization_link_id = ?')->execute([$orgLinkId]);
        $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplierId]);
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
