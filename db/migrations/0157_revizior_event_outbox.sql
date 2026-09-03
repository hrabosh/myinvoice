-- ReviziOR R5: transakční outbox událostí o dokladu.
--
-- Řádek vzniká ve **stejné transakci** jako business změna, takže se nemůže
-- stát, že se faktura vystaví a událost se ztratí (ani naopak). Payload je
-- immutable snapshot; dispatcher ho po pozdější změně faktury neregeneruje.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS revizior_event_outbox (
  id                   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  organization_link_id BIGINT UNSIGNED NOT NULL,
  invoice_link_id      BIGINT UNSIGNED NULL,
  aggregate_type       VARCHAR(32) NOT NULL,
  aggregate_id         VARCHAR(64) NOT NULL,
  aggregate_sequence   BIGINT UNSIGNED NOT NULL,
  event_type           VARCHAR(64) NOT NULL,
  spec_version         VARCHAR(16) NOT NULL,
  payload_json         JSON NOT NULL,
  payload_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state                VARCHAR(16) NOT NULL,
  delivery_attempts    INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at      DATETIME(6) NOT NULL,
  last_http_status     SMALLINT UNSIGNED NULL,
  last_error_code      VARCHAR(64) NULL,
  claimed_at           DATETIME(6) NULL,
  claimed_by           VARCHAR(64) NULL,
  created_at           DATETIME(6) NOT NULL,
  delivered_at         DATETIME(6) NULL,
  UNIQUE KEY uq_revizior_outbox_invoice_sequence (invoice_link_id, aggregate_sequence),
  KEY idx_revizior_outbox_pending (state, next_attempt_at),
  KEY idx_revizior_outbox_claimed (claimed_at),
  CONSTRAINT fk_revizior_outbox_organization
    FOREIGN KEY (organization_link_id) REFERENCES revizior_organization_links(id) ON DELETE RESTRICT,
  CONSTRAINT fk_revizior_outbox_invoice
    FOREIGN KEY (invoice_link_id) REFERENCES revizior_invoice_links(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
