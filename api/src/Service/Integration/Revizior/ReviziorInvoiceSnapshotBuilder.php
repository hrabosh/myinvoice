<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Normalizovaný snapshot dokladu pro ReviziOR (kontrakt `InvoiceSnapshot`).
 *
 * Stejný tvar vrací POST invoice-drafts, GET invoices/{key} i (R5) event
 * outbox — proto jedno místo. Částky jdou v minor units podle desetinných míst
 * měny; nic se nepočítá znovu, čte se `total_with_vat` a `amount_to_pay`
 * spočítané `InvoiceCalculator`em.
 *
 * Stav se mapuje na uzavřený výčet kontraktu: MyInvoice `reminded` je „po
 * splatnosti s upomínkou“, tedy `overdue`; vystavený doklad po splatnosti bez
 * upomínky je `overdue` také; částečná úhrada se pozná z `paid_total`.
 * `rawStatus` nese původní hodnotu.
 */
final class ReviziorInvoiceSnapshotBuilder
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param array<string,mixed> $invoice řádek z `InvoiceRepository::find()`
     * @return array<string,mixed>
     */
    public function build(array $invoice, string $externalInvoiceKey, int $sequence, ?string $today = null): array
    {
        $decimals = (int) ($invoice['currency_decimals'] ?? 2);
        $factor = 10 ** $decimals;
        $rawStatus = (string) $invoice['status'];
        $status = $this->status($invoice, $today ?? date('Y-m-d'));
        $invoiceId = (int) $invoice['id'];
        $publicToken = isset($invoice['public_token']) && is_string($invoice['public_token']) && $invoice['public_token'] !== ''
            ? $invoice['public_token']
            : null;
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');

        return [
            'externalInvoiceKey' => $externalInvoiceKey,
            'invoiceId' => (string) $invoiceId,
            'invoiceNumber' => isset($invoice['varsymbol']) && $invoice['varsymbol'] !== '' ? (string) $invoice['varsymbol'] : null,
            'status' => $status,
            'rawStatus' => $rawStatus,
            'currency' => strtoupper((string) $invoice['currency']),
            'totalMinor' => (int) round((float) ($invoice['total_with_vat'] ?? 0) * $factor),
            // Zbývá uhradit = k úhradě mínus už zaplacené. `amount_to_pay` je
            // generovaný sloupec (celkem mínus záloha) a platby v něm nejsou;
            // bez odečtení by consumer u částečně uhrazené faktury pořád ukazoval
            // plnou částku.
            'amountDueMinor' => $this->amountDueMinor($invoice, $rawStatus, $factor),
            'issueDate' => (string) $invoice['issue_date'],
            'dueDate' => (string) $invoice['due_date'],
            'editPath' => $rawStatus === 'draft' ? "/invoices/{$invoiceId}/edit" : "/invoices/{$invoiceId}",
            'publicUrl' => $publicToken !== null && $rawStatus !== 'draft' && $appUrl !== '' ? $appUrl . '/invoice/' . $publicToken : null,
            'pdfUrl' => null,
            'sequence' => $sequence,
        ];
    }

    /**
     * Data události (§2.15 kontraktu).
     *
     * Stejné hodnoty jako snapshot odpovědi, ale jinak pojmenované: událost
     * nese **okamžiky** změny stavu (`issuedAt`, `dueAt`, `paidAt`), odpověď
     * kalendářní data dokladu. `invoiceId`, `externalKey` ani `sequence` tu
     * nejsou — patří do obálky a duplikovat je znamená mít dvě pravdy.
     *
     * @param array<string,mixed> $invoice
     *
     * @return array<string,mixed>
     */
    public function eventData(array $invoice, ?string $today = null): array
    {
        $snapshot = $this->build($invoice, '00000000-0000-4000-8000-000000000000', 0, $today);
        $issuedAt = $this->timestampOrNull($invoice, 'issued_at') ?? $this->timestampOrNull($invoice, 'sent_at');

        return [
            'invoiceNumber' => $snapshot['invoiceNumber'],
            'status' => $snapshot['status'],
            'rawStatus' => $snapshot['rawStatus'],
            'currency' => $snapshot['currency'],
            'totalMinor' => $snapshot['totalMinor'],
            'amountDueMinor' => $snapshot['amountDueMinor'],
            // Vystavení: doklad ho nemá jako timestamp, takže se posílá datum
            // vystavení — u konceptu `null`, protože vystavený ještě není.
            'issuedAt' => $snapshot['rawStatus'] === 'draft' ? null : ($issuedAt ?? $snapshot['issueDate']),
            'dueAt' => $snapshot['dueDate'],
            'paidAt' => isset($invoice['paid_at']) && $invoice['paid_at'] !== null && $invoice['paid_at'] !== ''
                ? (string) $invoice['paid_at']
                : null,
            'editPath' => $snapshot['editPath'],
            'publicUrl' => $snapshot['publicUrl'],
            'pdfUrl' => $snapshot['pdfUrl'],
        ];
    }

    /** @param array<string,mixed> $invoice */
    private function amountDueMinor(array $invoice, string $rawStatus, int $factor): int
    {
        if ($rawStatus === 'cancelled') {
            return 0;
        }
        $due = (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat'] ?? 0);
        $paid = (float) ($invoice['paid_total'] ?? 0);

        return max(0, (int) round(($due - $paid) * $factor));
    }

    /** @param array<string,mixed> $invoice */
    private function timestampOrNull(array $invoice, string $field): ?string
    {
        $value = $invoice[$field] ?? null;
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-')) {
            return null;
        }

        return str_contains($value, 'T') ? $value : str_replace(' ', 'T', $value) . 'Z';
    }

    /** @param array<string,mixed> $invoice */
    private function status(array $invoice, string $today): string
    {
        $raw = (string) $invoice['status'];
        $paidTotal = (float) ($invoice['paid_total'] ?? 0);
        $amountToPay = (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat'] ?? 0);
        return match ($raw) {
            'draft' => 'draft',
            'paid' => 'paid',
            'cancelled' => 'cancelled',
            'reminded' => $paidTotal > 0 && $paidTotal < $amountToPay ? 'partially_paid' : 'overdue',
            'issued', 'sent' => $paidTotal > 0 && $paidTotal < $amountToPay
                ? 'partially_paid'
                : (strcmp((string) $invoice['due_date'], $today) < 0 ? 'overdue' : $raw),
            default => 'unknown',
        };
    }
}
