<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

/**
 * Validace `POST /organizations/{uuid}/invoice-drafts` (kontrakt v1, §2.11).
 *
 * Kontrakt nese částky jako decimal stringy; tady se jen ověří tvar, výpočet
 * dělá až MyInvoice (`InvoiceCalculator`). Sazba DPH přichází jako procento
 * a na `vat_rate_id` ji překládá služba — tady se jen ověří, že je to číslo.
 */
final class ReviziorInvoiceDraftRequestValidator
{
    private const ALLOWED = ['specVersion', 'externalInvoiceKey', 'clientUuid', 'invoiceType', 'currency', 'issueDate', 'taxDate', 'dueDate', 'pricesIncludeVat', 'language', 'items'];
    private const ITEM_ALLOWED = ['externalLineKey', 'priceListCode', 'description', 'quantity', 'unit', 'unitPrice', 'vatRate', 'sourceReferences'];
    private const SOURCE_ALLOWED = ['type', 'uuid'];
    private const SOURCE_TYPES = ['revision_report', 'revision_schedule', 'revision_job', 'manual'];
    private const MAX_ITEMS = 500;
    private const DECIMAL = '/^-?[0-9]+(?:\.[0-9]+)?$/D';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /**
     * @param array<string,mixed> $body
     * @return array{
     *   organizationUuid:string, externalInvoiceKey:string, clientUuid:string, invoiceType:string,
     *   currency:string, issueDate:string, taxDate:?string, dueDate:string, pricesIncludeVat:bool,
     *   language:string,
     *   items:list<array{externalLineKey:string, priceListCode:?string, description:string, quantity:string,
     *     unit:string, unitPrice:string, vatRate:string, sourceReferences:list<array{type:string,uuid:string}>}>
     * }
     */
    public function validate(string $organizationUuid, array $body, string $idempotencyKey): array
    {
        $fields = [];
        $organizationUuid = $this->uuid($organizationUuid, 'organizationUuid', $fields);
        if ($idempotencyKey === '') {
            $fields['Idempotency-Key'] = 'required';
        } elseif (strlen($idempotencyKey) > 255) {
            $fields['Idempotency-Key'] = 'too_long';
        }
        $this->rejectUnknown($body, self::ALLOWED, '', $fields);

        if (($body['specVersion'] ?? null) !== ReviziorContract::VERSION) {
            $fields['specVersion'] = 'must_equal_1.0';
        }
        $externalInvoiceKey = $this->uuid((string) ($body['externalInvoiceKey'] ?? ''), 'externalInvoiceKey', $fields);
        $clientUuid = $this->uuid((string) ($body['clientUuid'] ?? ''), 'clientUuid', $fields);

        $invoiceType = $body['invoiceType'] ?? null;
        if (!is_string($invoiceType) || !in_array($invoiceType, ['invoice', 'proforma', 'credit_note'], true)) {
            $fields['invoiceType'] = 'invalid_value';
            $invoiceType = 'invoice';
        } elseif ($invoiceType === 'credit_note') {
            // Dobropis vzniká z vystaveného dokladu, ne jako samostatný koncept
            // (capability `creditNote` provider zatím neinzeruje).
            $fields['invoiceType'] = 'unsupported';
        }

        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            $fields['currency'] = 'invalid_currency';
        }
        $issueDate = $this->date($body['issueDate'] ?? null, 'issueDate', $fields);
        $dueDate = $this->date($body['dueDate'] ?? null, 'dueDate', $fields);
        $taxDate = null;
        if (!array_key_exists('taxDate', $body)) {
            $fields['taxDate'] = 'required_nullable';
        } elseif ($body['taxDate'] !== null) {
            $taxDate = $this->date($body['taxDate'], 'taxDate', $fields);
        }
        if ($issueDate !== '' && $dueDate !== '' && strcmp($dueDate, $issueDate) < 0) {
            $fields['dueDate'] = 'before_issue_date';
        }

        if (!array_key_exists('pricesIncludeVat', $body) || !is_bool($body['pricesIncludeVat'])) {
            $fields['pricesIncludeVat'] = 'required_boolean';
        }
        $pricesIncludeVat = is_bool($body['pricesIncludeVat'] ?? null) ? $body['pricesIncludeVat'] : false;

        $language = $body['language'] ?? null;
        if (!is_string($language) || !in_array($language, ['cs', 'en'], true)) {
            $fields['language'] = 'invalid_value';
            $language = 'cs';
        }

        $items = [];
        $rawItems = $body['items'] ?? null;
        if (!is_array($rawItems) || !array_is_list($rawItems) || $rawItems === []) {
            $fields['items'] = 'required_non_empty_array';
        } elseif (count($rawItems) > self::MAX_ITEMS) {
            $fields['items'] = 'too_many';
        } else {
            $seenLineKeys = [];
            foreach ($rawItems as $i => $item) {
                $prefix = "items.{$i}.";
                if (!is_array($item)) {
                    $fields["items.{$i}"] = 'required_object';
                    continue;
                }
                $this->rejectUnknown($item, self::ITEM_ALLOWED, $prefix, $fields);
                $lineKey = $this->uuid((string) ($item['externalLineKey'] ?? ''), $prefix . 'externalLineKey', $fields);
                if ($lineKey !== '' && isset($seenLineKeys[$lineKey])) {
                    $fields[$prefix . 'externalLineKey'] = 'duplicate';
                }
                $seenLineKeys[$lineKey] = true;

                $priceListCode = null;
                if (!array_key_exists('priceListCode', $item)) {
                    $fields[$prefix . 'priceListCode'] = 'required_nullable';
                } elseif ($item['priceListCode'] !== null) {
                    if (!is_string($item['priceListCode']) || trim($item['priceListCode']) === '' || mb_strlen($item['priceListCode']) > 50) {
                        $fields[$prefix . 'priceListCode'] = 'invalid_value';
                    } else {
                        $priceListCode = trim($item['priceListCode']);
                    }
                }

                $description = is_string($item['description'] ?? null) ? trim($item['description']) : '';
                if ($description === '') {
                    $fields[$prefix . 'description'] = 'required';
                }
                $quantity = $this->decimal($item['quantity'] ?? null, $prefix . 'quantity', $fields);
                if ($quantity !== '' && (float) $quantity == 0.0) {
                    $fields[$prefix . 'quantity'] = 'must_not_be_zero';
                }
                $unit = is_string($item['unit'] ?? null) ? trim($item['unit']) : '';
                if ($unit === '' || mb_strlen($unit) > 20) {
                    $fields[$prefix . 'unit'] = 'invalid_value';
                }
                $unitPrice = $this->decimal($item['unitPrice'] ?? null, $prefix . 'unitPrice', $fields);
                $vatRate = $this->decimal($item['vatRate'] ?? null, $prefix . 'vatRate', $fields);
                if ($vatRate !== '' && ((float) $vatRate < 0 || (float) $vatRate > 100)) {
                    $fields[$prefix . 'vatRate'] = 'out_of_range';
                }

                $sources = [];
                $rawSources = $item['sourceReferences'] ?? null;
                if (!is_array($rawSources) || !array_is_list($rawSources)) {
                    $fields[$prefix . 'sourceReferences'] = 'required_array';
                } else {
                    foreach ($rawSources as $j => $source) {
                        $sp = $prefix . "sourceReferences.{$j}.";
                        if (!is_array($source)) {
                            $fields[$prefix . "sourceReferences.{$j}"] = 'required_object';
                            continue;
                        }
                        $this->rejectUnknown($source, self::SOURCE_ALLOWED, $sp, $fields);
                        $type = $source['type'] ?? null;
                        if (!is_string($type) || !in_array($type, self::SOURCE_TYPES, true)) {
                            $fields[$sp . 'type'] = 'invalid_value';
                            $type = 'manual';
                        }
                        $sources[] = ['type' => $type, 'uuid' => $this->uuid((string) ($source['uuid'] ?? ''), $sp . 'uuid', $fields)];
                    }
                }

                $items[] = [
                    'externalLineKey' => $lineKey,
                    'priceListCode' => $priceListCode,
                    'description' => $description,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unitPrice' => $unitPrice,
                    'vatRate' => $vatRate,
                    'sourceReferences' => $sources,
                ];
            }
        }

        if ($fields !== []) {
            throw ReviziorProvisioningException::invoiceValidation($fields);
        }

        return [
            'organizationUuid' => $organizationUuid,
            'externalInvoiceKey' => $externalInvoiceKey,
            'clientUuid' => $clientUuid,
            'invoiceType' => $invoiceType,
            'currency' => $currency,
            'issueDate' => $issueDate,
            'taxDate' => $taxDate,
            'dueDate' => $dueDate,
            'pricesIncludeVat' => $pricesIncludeVat,
            'language' => $language,
            'items' => $items,
        ];
    }

    /** @param array<string,string> $fields */
    public function validateKey(string $organizationUuid, string $externalInvoiceKey): array
    {
        $fields = [];
        $organizationUuid = $this->uuid($organizationUuid, 'organizationUuid', $fields);
        $externalInvoiceKey = $this->uuid($externalInvoiceKey, 'externalInvoiceKey', $fields);
        if ($fields !== []) {
            throw ReviziorProvisioningException::invoiceValidation($fields);
        }
        return ['organizationUuid' => $organizationUuid, 'externalInvoiceKey' => $externalInvoiceKey];
    }

    /** @param array<string,string> $fields */
    private function uuid(string $value, string $field, array &$fields): string
    {
        $value = strtolower(trim($value));
        if (preg_match(self::UUID, $value) !== 1) {
            $fields[$field] = 'must_be_uuid';
            return '';
        }
        return $value;
    }

    /** @param array<string,string> $fields */
    private function decimal(mixed $value, string $field, array &$fields): string
    {
        if (!is_string($value) || preg_match(self::DECIMAL, $value) !== 1) {
            $fields[$field] = 'must_be_decimal_string';
            return '';
        }
        return $value;
    }

    /** @param array<string,string> $fields */
    private function date(mixed $value, string $field, array &$fields): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            $fields[$field] = 'must_be_date';
            return '';
        }
        return $value;
    }

    /** @param array<string,mixed> $body @param list<string> $allowed @param array<string,string> $fields */
    private function rejectUnknown(array $body, array $allowed, string $prefix, array &$fields): void
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $fields[$prefix . (string) $key] = 'unknown_field';
            }
        }
    }
}
