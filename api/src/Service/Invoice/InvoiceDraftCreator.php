<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Action\Invoice\HandlesVarsymbolDuplicate;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Validation\InvoiceValidation;
use MyInvoice\Service\WriteActor;

/**
 * Jediná cesta, kterou vzniká koncept vydaného dokladu.
 *
 * Vytaženo z `CreateInvoiceAction` (R3 integrace ReviziOR, §2.2 zadání), aby
 * integrační endpoint nekopíroval defaulty, validaci, výpočet ani audit.
 * Pořadí kroků je shodné s původní action:
 *
 * 1. `InvoiceDefaults::resolve` — měna, jazyk, splatnost, DUZP;
 * 2. `InvoiceValidation::invoice`;
 * 3. klient musí patřit dodavateli (proti cross-supplier injection);
 * 4. auto-default klasifikace DPH;
 * 5. `InvoiceRepository::createDraft` + položky;
 * 6. `InvoiceCalculator::recompute`;
 * 7. kurz podle DUZP;
 * 8. activity log `invoice.created`.
 *
 * Služba **neotvírá transakci** — stejně jako původní action. Volající, který
 * atomicitu potřebuje (integrace: doklad + externí vazba + idempotence), si
 * ji otevře sám; repository sdílí jedno PDO.
 */
final class InvoiceDraftCreator
{
    use HandlesVarsymbolDuplicate;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly VatClassificationDefaulter $vatDefaulter,
        private readonly ActivityLogger $logger,
        private readonly ExchangeRateApplier $rateApplier,
    ) {}

    /**
     * @param array<string,mixed> $body
     * @return array{invoice: array<string,mixed>, exchange_rate: array<string,mixed>|null}
     * @throws InvoiceDraftException
     */
    public function create(int $supplierId, array $body, WriteActor $actor): array
    {
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            throw InvoiceDraftException::integrity($e->getMessage());
        }

        $errors = InvoiceValidation::invoice($body, $this->repo->vatRateMap(), $this->repo->vatRateCountryMap());
        if (!empty($errors)) {
            throw InvoiceDraftException::validation($errors);
        }

        $client = $this->clients->find((int) $body['client_id']);
        if ($client === null || (int) ($client['supplier_id'] ?? 0) !== $supplierId) {
            throw InvoiceDraftException::clientNotFound();
        }

        $userId = (int) ($actor->userId ?? 0);
        $this->applyVatClassificationDefaults($body, $supplierId);

        try {
            $id = $this->repo->createDraft($body, $userId);
        } catch (\InvalidArgumentException $e) {
            throw InvoiceDraftException::integrity($e->getMessage());
        } catch (\PDOException $e) {
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                throw InvoiceDraftException::varsymbolDuplicate($dupMsg);
            }
            throw $e;
        }
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);
        $rateMeta = $this->rateApplier->applyToInvoice($id);

        $this->logger->log('invoice.created', $userId, 'invoice', $id, [
            'client_id' => $body['client_id'],
            'type'      => $body['invoice_type'] ?? 'invoice',
        ], $actor->ip, $actor->userAgent);

        return [
            'invoice' => $this->repo->find($id) ?? [],
            'exchange_rate' => $rateMeta,
        ];
    }

    /**
     * Auto-default vat_classification_code podle vat_rate na řádcích a header.
     * Aplikuje se jen pokud user nezadal (NULL nebo prázdný).
     *
     * @param array<string,mixed> $body
     */
    private function applyVatClassificationDefaults(array &$body, int $supplierId): void
    {
        $vatRates = $this->repo->vatRateMap();
        $reverseCharge = !empty($body['reverse_charge']);
        // Country-aware RC: tuzemský odběratel → §92a (ř.25), zahraniční EU → dodání do JČS (ř.20).
        $customerEuForeign = $reverseCharge
            && (int) ($body['client_id'] ?? 0) > 0
            && $this->repo->clientIsEuForeign((int) $body['client_id']);

        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as &$item) {
                if (!empty($item['vat_classification_code'])) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $taxDate = $body['tax_date'] ?? $body['issue_date'] ?? null;
                // Měrná jednotka řádku je signál zboží/služba pro RC prodej do EU (ř.20 vs ř.21).
                $units = ((string) ($item['unit'] ?? '') !== '') ? [(string) $item['unit']] : [];
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForSale($rate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign, $units);
            }
            unset($item);
        }

        if (empty($body['vat_classification_code']) && !empty($body['items'])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100), 'unit' => (string) ($it['unit'] ?? '')];
            }, (array) $body['items']);
            $body['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                $itemsWithTotals,
                (bool) ($body['reverse_charge'] ?? false),
                'sale',
                $body['tax_date'] ?? $body['issue_date'] ?? null,
                $supplierId,
                $customerEuForeign,
            );
        }
    }
}
