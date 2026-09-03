<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;
use PDOException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Připojení vydaného PDF k dokladu (R6, §2.12 zadání).
 *
 * ## Nic se nebuffruje v paměti
 *
 * Tělo se čte po blocích do dočasného privátního souboru a průběžně se počítá
 * SHA-256. Dvacetimegová příloha tak nezvedne paměť requestu — a limit se
 * pozná dřív, než se celá načte.
 *
 * ## Digest se ověří před finálním přesunem
 *
 * Pořadí je: uložit do temp → ověřit velikost, MIME, magic bytes a digest →
 * teprve pak přesunout na místo a zapsat do DB. Obrácené pořadí by nechalo
 * v úložišti soubory, které neprošly kontrolou.
 *
 * ## Idempotence podle klíče a digestu
 *
 * Týž externí klíč se stejným digestem vrátí původní přílohu (`200`), s jiným
 * digestem je `409`. Bez toho by retry po timeoutu připojil doklad dvakrát.
 */
final class ReviziorAttachmentService
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    private const CHUNK_BYTES = 262144;
    private const PDF_MAGIC = '%PDF-';

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly ActivityLogger $activity,
        private readonly ReviziorInvoiceDraftRequestValidator $validator,
    ) {}

    /**
     * @return array{data: array<string,mixed>, created: bool}
     */
    public function store(
        string $organizationUuid,
        string $externalInvoiceKey,
        string $externalAttachmentKey,
        StreamInterface $body,
        string $contentType,
        ?string $digestHeader,
        ?int $contentLength,
        ?string $fileName,
    ): array {
        $key = $this->validator->validateKey($organizationUuid, $externalInvoiceKey);
        $attachmentKey = $this->attachmentKey($externalAttachmentKey);
        $expectedSha = $this->expectedSha256($digestHeader);

        if (!str_starts_with(strtolower(trim($contentType)), 'application/pdf')) {
            throw ReviziorProvisioningException::attachmentInvalid('unsupported_content_type');
        }
        if ($contentLength !== null && $contentLength > self::MAX_BYTES) {
            throw ReviziorProvisioningException::attachmentTooLarge();
        }

        $pdo = $this->db->pdo();
        $organization = $this->organization($pdo, $key['organizationUuid']);
        if ($organization === null) {
            throw ReviziorProvisioningException::notFound('organization_not_provisioned');
        }
        $invoiceLink = $this->invoiceLink($pdo, (int) $organization['id'], $key['externalInvoiceKey']);
        if ($invoiceLink === null) {
            throw ReviziorProvisioningException::notFound('invoice_not_found');
        }
        $invoice = $this->invoices->find((int) $invoiceLink['invoice_id']);
        if ($invoice === null || (int) $invoice['supplier_id'] !== (int) $organization['supplier_id']) {
            throw ReviziorProvisioningException::notFound('invoice_not_found');
        }
        if (in_array((string) $invoice['status'], ['cancelled'], true)
            || (string) $invoice['invoice_type'] === 'cancellation'
        ) {
            throw ReviziorProvisioningException::conflict('invoice_not_editable');
        }

        // Existující vazba se řeší před čtením těla: opakovaný upload téhož
        // obsahu nemá cenu znovu přenášet ani ukládat.
        $existing = $this->attachmentLink($pdo, (int) $invoiceLink['id'], $attachmentKey);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['sha256_hex'], $expectedSha)) {
                throw ReviziorProvisioningException::conflict('attachment_digest_mismatch');
            }

            return [
                'data' => $this->responseData($existing['attachment_id'], (string) $existing['sha256_hex'], (int) $existing['size_bytes']),
                'created' => false,
            ];
        }

        [$temporaryPath, $sizeBytes, $sha256] = $this->streamToTemporaryFile($body);

        try {
            if ($sizeBytes === 0) {
                throw ReviziorProvisioningException::attachmentInvalid('empty_body');
            }
            if ($contentLength !== null && $contentLength !== $sizeBytes) {
                // Deklarovaná a skutečná délka se musí rovnat, jinak nevíme,
                // jestli nám nepřišel zkrácený soubor.
                throw ReviziorProvisioningException::attachmentInvalid('content_length_mismatch');
            }
            if (!hash_equals($expectedSha, $sha256)) {
                throw ReviziorProvisioningException::conflict('attachment_digest_mismatch');
            }
            $this->assertPdf($temporaryPath);

            return $this->persist($pdo, $invoice, $invoiceLink, $organization, $attachmentKey, $temporaryPath, $sizeBytes, $sha256, $fileName);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $invoiceLink
     * @param array<string,mixed> $organization
     *
     * @return array{data: array<string,mixed>, created: bool}
     */
    private function persist(
        PDO $pdo,
        array $invoice,
        array $invoiceLink,
        array $organization,
        string $attachmentKey,
        string $temporaryPath,
        int $sizeBytes,
        string $sha256,
        ?string $fileName,
    ): array {
        $invoiceId = (int) $invoice['id'];
        $supplierId = (int) $invoice['supplier_id'];
        // Jméno souboru v úložišti generujeme sami — z hlavičky se bere jen
        // zobrazovaný název, a ten se sanitizuje.
        $storedName = 'revizior-' . $attachmentKey . '.pdf';
        $displayName = $this->safeDisplayName($fileName);
        $directory = InvoiceAttachmentRepository::dirFor($supplierId, $invoiceId);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw ReviziorProvisioningException::attachmentInvalid('storage_unavailable');
        }
        $targetPath = $directory . '/' . $storedName;

        $pdo->beginTransaction();
        try {
            $attachmentId = $this->attachments->insert(
                $invoiceId,
                $storedName,
                $displayName,
                $sizeBytes,
                $sha256,
                'application/pdf',
                null,
            );
            $pdo->prepare(
                'INSERT INTO revizior_attachment_links
                    (invoice_link_id, external_attachment_key, attachment_id, sha256_hex, size_bytes, created_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
            )->execute([(int) $invoiceLink['id'], $attachmentKey, $attachmentId, $sha256, $sizeBytes]);

            if (!@rename($temporaryPath, $targetPath) && !@copy($temporaryPath, $targetPath)) {
                throw ReviziorProvisioningException::attachmentInvalid('storage_unavailable');
            }

            $this->activity->log(
                'revizior.invoice.attachment_stored',
                null,
                'invoice',
                $invoiceId,
                [
                    'organization_uuid' => (string) $organization['organization_uuid'],
                    'external_attachment_key' => $attachmentKey,
                    'sha256' => 'sha256:' . $sha256,
                    'size_bytes' => $sizeBytes,
                ],
                supplierId: $supplierId,
            );
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($targetPath);
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw ReviziorProvisioningException::conflict('attachment_conflict');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($targetPath);
            throw $e;
        }

        return ['data' => $this->responseData((string) $attachmentId, $sha256, $sizeBytes), 'created' => true];
    }

    /**
     * @return array{0:string, 1:int, 2:string} cesta, velikost, digest
     */
    private function streamToTemporaryFile(StreamInterface $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'revizior-attachment-');
        if ($path === false) {
            throw ReviziorProvisioningException::attachmentInvalid('storage_unavailable');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);
            throw ReviziorProvisioningException::attachmentInvalid('storage_unavailable');
        }
        $context = hash_init('sha256');
        $size = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > self::MAX_BYTES) {
                    throw ReviziorProvisioningException::attachmentTooLarge();
                }
                hash_update($context, $chunk);
                if (fwrite($handle, $chunk) === false) {
                    throw ReviziorProvisioningException::attachmentInvalid('storage_unavailable');
                }
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);

        return [$path, $size, hash_final($context)];
    }

    private function assertPdf(string $path): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ReviziorProvisioningException::attachmentInvalid('storage_unavailable');
        }
        $header = (string) fread($handle, strlen(self::PDF_MAGIC));
        fclose($handle);
        if ($header !== self::PDF_MAGIC) {
            throw ReviziorProvisioningException::attachmentInvalid('not_a_pdf');
        }
        // `finfo` je druhá nezávislá kontrola: hlavička se dá podvrhnout
        // u souboru, který PDF parser stejně odmítne.
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($detected !== 'application/pdf') {
            throw ReviziorProvisioningException::attachmentInvalid('not_a_pdf');
        }
    }

    private function attachmentKey(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw ReviziorProvisioningException::attachmentInvalid('external_attachment_key_must_be_uuid');
        }

        return $value;
    }

    private function expectedSha256(?string $digestHeader): string
    {
        $value = trim((string) $digestHeader);
        if (!str_starts_with(strtolower($value), 'sha-256=')) {
            throw ReviziorProvisioningException::attachmentInvalid('digest_header_required');
        }
        $base64 = substr($value, strlen('sha-256='));
        $raw = base64_decode($base64, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw ReviziorProvisioningException::attachmentInvalid('digest_header_invalid');
        }

        return bin2hex($raw);
    }

    /** Zobrazovaný název: bez cest, bez řídicích znaků, vždy `.pdf`. */
    private function safeDisplayName(?string $fileName): string
    {
        $name = trim((string) $fileName);
        if ($name === '') {
            return 'revizni-zprava.pdf';
        }
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '-', $name);
        $name = trim($name, '.- ');
        if ($name === '' || strlen($name) > 200) {
            $name = 'revizni-zprava.pdf';
        }

        return str_ends_with(strtolower($name), '.pdf') ? $name : $name . '.pdf';
    }

    /** @return array<string,mixed> */
    private function responseData(string|int $attachmentId, string $sha256, int $sizeBytes): array
    {
        return [
            'attachmentId' => (string) $attachmentId,
            'sha256' => $sha256,
            'sizeBytes' => $sizeBytes,
        ];
    }

    /** @return array<string,mixed>|null */
    private function organization(PDO $pdo, string $organizationUuid): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, supplier_id, status, organization_uuid FROM revizior_organization_links WHERE organization_uuid = ?'
        );
        $statement->execute([$organizationUuid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function invoiceLink(PDO $pdo, int $organizationLinkId, string $externalInvoiceKey): ?array
    {
        $statement = $pdo->prepare(
            'SELECT id, invoice_id FROM revizior_invoice_links WHERE organization_link_id = ? AND external_invoice_key = ?'
        );
        $statement->execute([$organizationLinkId, $externalInvoiceKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function attachmentLink(PDO $pdo, int $invoiceLinkId, string $attachmentKey): ?array
    {
        $statement = $pdo->prepare(
            'SELECT attachment_id, sha256_hex, size_bytes
               FROM revizior_attachment_links
              WHERE invoice_link_id = ? AND external_attachment_key = ?'
        );
        $statement->execute([$invoiceLinkId, $attachmentKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
