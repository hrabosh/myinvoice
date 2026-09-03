-- ReviziOR R3: vazba klienta na ReviziOR UUID.
--
-- Identita klienta v integraci je výhradně tahle tabulka — nikdy IČO ani
-- e-mail. Klient patří dodavateli organization linku; `uq_revizior_client_internal`
-- brání tomu, aby jeden klient měl dvě externí identity.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS revizior_client_links (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_link_id BIGINT UNSIGNED NOT NULL,
  client_uuid          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  client_id            BIGINT UNSIGNED NOT NULL,
  payload_hash         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_updated_at    DATETIME(6) NULL,
  created_at           DATETIME(6) NOT NULL,
  updated_at           DATETIME(6) NOT NULL,
  UNIQUE KEY uq_revizior_client_external (organization_link_id, client_uuid),
  UNIQUE KEY uq_revizior_client_internal (organization_link_id, client_id),
  KEY idx_revizior_client_uuid (client_uuid),
  CONSTRAINT fk_revizior_client_organization
    FOREIGN KEY (organization_link_id) REFERENCES revizior_organization_links(id) ON DELETE RESTRICT,
  CONSTRAINT fk_revizior_client_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
