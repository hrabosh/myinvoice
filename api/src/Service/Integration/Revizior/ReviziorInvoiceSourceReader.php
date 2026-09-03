<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration\Revizior;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Zdroje dokladu v ReviziORu — read model pro odkaz „Otevřít revizi" (R6).
 *
 * ## Odkaz se skládá tady, ne v prohlížeči
 *
 * Origin bere z `deployment.revizior.app_url`, tedy z konfigurace instalace,
 * ne z čehokoli, co přišlo requestem. Frontend dostane hotovou absolutní URL
 * nebo `null` — jinak by se odkaz dal ovlivnit vstupem a vznikl by open redirect
 * na doméně poskytovatele.
 *
 * ## Odkazuje se jen to, co v ReviziORu má obrazovku
 *
 * Dnes existuje detail revizní zprávy (`/revize/zpravy/{uuid}`). Ostatní typy
 * zdrojů se vrací bez URL — vypsat je jako text je pravdivé, uhodnout cestu
 * a poslat uživatele na 404 ne.
 */
final class ReviziorInvoiceSourceReader
{
    private const REPORT_PATH = '/revize/zpravy/';

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * @return list<array{type:string, uuid:string, externalLineKey:?string, description:?string, priceListCode:?string, url:?string}>
     */
    public function forInvoice(int $invoiceId, int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT s.source_type, s.source_uuid, s.external_line_key, s.metadata_json
               FROM revizior_invoice_sources s
               JOIN revizior_invoice_links l ON l.id = s.invoice_link_id
               JOIN revizior_organization_links o ON o.id = l.organization_link_id
               JOIN invoices i ON i.id = l.invoice_id
              WHERE l.invoice_id = ? AND i.supplier_id = ? AND o.supplier_id = ?
              ORDER BY s.id'
        );
        $statement->execute([$invoiceId, $supplierId, $supplierId]);

        $sources = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $type = (string) $row['source_type'];
            $uuid = (string) $row['source_uuid'];

            $sources[] = [
                'type' => $type,
                'uuid' => $uuid,
                'externalLineKey' => $row['external_line_key'] !== null ? (string) $row['external_line_key'] : null,
                'description' => isset($metadata['description']) && is_string($metadata['description'])
                    ? $metadata['description']
                    : null,
                'priceListCode' => isset($metadata['price_list_code']) && is_string($metadata['price_list_code'])
                    ? $metadata['price_list_code']
                    : null,
                'url' => $this->url($type, $uuid),
            ];
        }

        return $sources;
    }

    private function url(string $type, string $uuid): ?string
    {
        if ($type !== 'revision_report') {
            return null;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', strtolower($uuid)) !== 1) {
            return null;
        }
        $origin = $this->appOrigin();

        return $origin === null ? null : $origin . self::REPORT_PATH . strtolower($uuid);
    }

    /** Origin ReviziORu z konfigurace; `app_url` může nést i cestu modulu. */
    private function appOrigin(): ?string
    {
        $appUrl = trim((string) $this->config->get('deployment.revizior.app_url', ''));
        if ($appUrl === '') {
            return null;
        }
        $parts = parse_url($appUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
