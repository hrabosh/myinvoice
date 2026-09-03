<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Jediné místo, kde vzniká událost o dokladu pro ReviziOR (R5, §2.15).
 *
 * ## Zapisuje se v transakci volajícího
 *
 * Když už transakce běží, publisher se do ní přidá; jinak si otevře vlastní.
 * Business změna a událost tak buď platí obě, nebo žádná — faktura se nemůže
 * vystavit bez události ani naopak.
 *
 * ## Doklad bez vazby je no-op
 *
 * Standalone faktura (a v managed instalaci i faktura založená ručně v UI
 * mimo ReviziOR) žádnou událost negeneruje. Publisher se proto smí volat
 * odkudkoli bez podmínek u volajícího.
 *
 * ## Selhání publisheru neshodí fakturaci
 *
 * Výjimka se zaloguje a spolkne **jen** tehdy, když si publisher otevřel
 * vlastní transakci. Uvnitř cizí transakce se propaguje: tam už je pozdě
 * dělat, že se nic nestalo, protože rollback musí zahodit i business změnu.
 */
final class ReviziorInvoiceEventPublisher
{
    public const SPEC_VERSION = '1.0';

    /** Události, které kontrakt v1 zná. */
    public const TYPE_DRAFT_CREATED = 'invoice.draft_created';
    public const TYPE_UPDATED = 'invoice.updated';
    public const TYPE_ISSUED = 'invoice.issued';
    public const TYPE_SENT = 'invoice.sent';
    public const TYPE_PAYMENT_RECORDED = 'invoice.payment_recorded';
    public const TYPE_PARTIALLY_PAID = 'invoice.partially_paid';
    public const TYPE_PAID = 'invoice.paid';
    public const TYPE_OVERDUE = 'invoice.overdue';
    public const TYPE_CANCELLED = 'invoice.cancelled';
    public const TYPE_CREDIT_NOTE_ISSUED = 'invoice.credit_note_issued';
    public const TYPE_DELETED_DRAFT = 'invoice.deleted_draft';

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly ReviziorInvoiceSnapshotBuilder $snapshots,
        private readonly CanonicalPayloadHasher $hasher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed>|null $invoice už načtený řádek dokladu (ušetří dotaz)
     */
    public function publish(int $invoiceId, string $eventType, ?array $invoice = null): void
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            $link = $this->lockLink($pdo, $invoiceId);
            if ($link === null) {
                if ($ownTransaction) {
                    $pdo->commit();
                }

                return;
            }

            $invoice ??= $this->invoices->find($invoiceId);
            if ($invoice === null) {
                if ($ownTransaction) {
                    $pdo->commit();
                }

                return;
            }

            $sequence = (int) $link['event_sequence'] + 1;
            $pdo->prepare(
                'UPDATE revizior_invoice_links SET event_sequence = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?'
            )->execute([$sequence, (int) $link['id']]);

            $payload = $this->envelope($eventType, $link, $invoice, $sequence);
            $pdo->prepare(
                'INSERT INTO revizior_event_outbox
                    (id, organization_link_id, invoice_link_id, aggregate_type, aggregate_id, aggregate_sequence,
                     event_type, spec_version, payload_json, payload_hash, state, delivery_attempts,
                     next_attempt_at, created_at)
                 VALUES (?, ?, ?, \'invoice\', ?, ?, ?, ?, ?, ?, \'pending\', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
            )->execute([
                (string) $payload['eventId'],
                (int) $link['organization_link_id'],
                (int) $link['id'],
                (string) $invoiceId,
                $sequence,
                $eventType,
                self::SPEC_VERSION,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->hasher->hash($payload),
            ]);

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!$ownTransaction) {
                // Uvnitř cizí transakce se chyba propaguje: událost je součást
                // business změny, ne vedlejší efekt po ní.
                throw $e;
            }
            $this->logger->error('ReviziOR outbox publish failed', [
                'invoice_id' => $invoiceId,
                'event_type' => $eventType,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Událost o úhradě: kromě `payment_recorded` se odvodí i výsledný stav.
     *
     * @param array<string,mixed>|null $invoice
     */
    public function publishPayment(int $invoiceId, ?array $invoice = null): void
    {
        $this->publish($invoiceId, self::TYPE_PAYMENT_RECORDED, $invoice);

        $invoice ??= $this->invoices->find($invoiceId);
        if ($invoice === null) {
            return;
        }
        $paid = (float) ($invoice['paid_total'] ?? 0);
        $due = (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat'] ?? 0);
        if ($paid <= 0.0) {
            return;
        }
        $this->publish(
            $invoiceId,
            $paid + 0.005 >= $due ? self::TYPE_PAID : self::TYPE_PARTIALLY_PAID,
            $invoice,
        );
    }

    /**
     * @param array<string,mixed> $link
     * @param array<string,mixed> $invoice
     *
     * @return array<string,mixed>
     */
    private function envelope(string $eventType, array $link, array $invoice, int $sequence): array
    {
        return [
            'specVersion' => self::SPEC_VERSION,
            'eventId' => Uuid::v4()->toRfc4122(),
            'eventType' => $eventType,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'organizationId' => (string) $link['organization_uuid'],
            'supplierId' => (string) $link['supplier_id'],
            'aggregate' => [
                'type' => 'invoice',
                'id' => (string) $invoice['id'],
                'externalKey' => (string) $link['external_invoice_key'],
                'sequence' => $sequence,
            ],
            'data' => $this->snapshots->eventData($invoice),
        ];
    }

    /** @return array<string,mixed>|null */
    private function lockLink(PDO $pdo, int $invoiceId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT ril.id, ril.organization_link_id, ril.external_invoice_key, ril.event_sequence,
                    rol.organization_uuid, rol.supplier_id
               FROM revizior_invoice_links ril
               JOIN revizior_organization_links rol ON rol.id = ril.organization_link_id
              WHERE ril.invoice_id = ?
                FOR UPDATE'
        );
        $statement->execute([$invoiceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
