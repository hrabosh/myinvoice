-- ReviziOR R3: vazba dokladu na ReviziOR externalInvoiceKey a zdrojové reference.
--
-- `invoice_id` je unikátní: jeden doklad MyInvoice nesmí patřit dvěma
-- externím klíčům. `event_sequence` je monotónní čítač událostí pro R5 outbox.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS revizior_invoice_links (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_link_id BIGINT UNSIGNED NOT NULL,
  external_invoice_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  invoice_id           BIGINT UNSIGNED NOT NULL,
  request_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_sequence       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at           DATETIME(6) NOT NULL,
  updated_at           DATETIME(6) NOT NULL,
  UNIQUE KEY uq_revizior_invoice_external (organization_link_id, external_invoice_key),
  UNIQUE KEY uq_revizior_invoice_internal (invoice_id),
  KEY idx_revizior_invoice_key (external_invoice_key),
  CONSTRAINT fk_revizior_invoice_organization
    FOREIGN KEY (organization_link_id) REFERENCES revizior_organization_links(id) ON DELETE RESTRICT,
  CONSTRAINT fk_revizior_invoice_invoice
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizior_invoice_sources (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_link_id   BIGINT UNSIGNED NOT NULL,
  source_type       VARCHAR(32) NOT NULL,
  source_uuid       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_line_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  metadata_json     JSON NULL,
  created_at        DATETIME(6) NOT NULL,
  UNIQUE KEY uq_revizior_invoice_source (invoice_link_id, source_type, source_uuid, external_line_key),
  KEY idx_revizior_invoice_source_lookup (source_type, source_uuid),
  CONSTRAINT fk_revizior_invoice_source_link
    FOREIGN KEY (invoice_link_id) REFERENCES revizior_invoice_links(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
