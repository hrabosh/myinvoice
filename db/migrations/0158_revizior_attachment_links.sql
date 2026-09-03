-- ReviziOR R6: vazba přílohy dokladu na externí klíč.
--
-- Externí klíč + digest drží idempotenci: opakované nahrání téhož obsahu vrátí
-- původní přílohu, jiný obsah pod stejným klíčem je konflikt. `attachment_id`
-- je unikátní, aby jedna příloha nepatřila dvěma externím klíčům.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS revizior_attachment_links (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_link_id         BIGINT UNSIGNED NOT NULL,
  external_attachment_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attachment_id           BIGINT UNSIGNED NOT NULL,
  sha256_hex              CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  size_bytes              BIGINT UNSIGNED NOT NULL,
  created_at              DATETIME(6) NOT NULL,
  UNIQUE KEY uq_revizior_attachment_external (invoice_link_id, external_attachment_key),
  UNIQUE KEY uq_revizior_attachment_internal (attachment_id),
  CONSTRAINT fk_revizior_attachment_invoice_link
    FOREIGN KEY (invoice_link_id) REFERENCES revizior_invoice_links(id) ON DELETE RESTRICT,
  CONSTRAINT fk_revizior_attachment_attachment
    FOREIGN KEY (attachment_id) REFERENCES invoice_attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
