-- ReviziOR R2: tenant links, idempotency a perzistentní replay ochrana.
--
-- Tabulky jsou aditivní a standalone běh je nepoužívá. Externí UUID se
-- ukládají v ASCII binární kolaci, aby unikátnost nebyla závislá na locale.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS revizior_organization_links (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  supplier_id       INT UNSIGNED NOT NULL,
  status            VARCHAR(32) NOT NULL,
  onboarding_state  VARCHAR(32) NOT NULL,
  payload_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_updated_at DATETIME(6) NULL,
  contract_version  VARCHAR(16) NOT NULL,
  created_at        DATETIME(6) NOT NULL,
  updated_at        DATETIME(6) NOT NULL,
  suspended_at      DATETIME(6) NULL,
  UNIQUE KEY uq_revizior_org_uuid (organization_uuid),
  UNIQUE KEY uq_revizior_org_supplier (supplier_id),
  KEY idx_revizior_org_status_updated (status, updated_at),
  CONSTRAINT fk_revizior_org_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizior_user_links (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_link_id BIGINT UNSIGNED NOT NULL,
  user_uuid             CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  supplier_role         VARCHAR(32) NOT NULL,
  active                TINYINT(1) NOT NULL DEFAULT 1,
  source_updated_at     DATETIME(6) NULL,
  session_version       BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at            DATETIME(6) NOT NULL,
  updated_at            DATETIME(6) NOT NULL,
  revoked_at            DATETIME(6) NULL,
  UNIQUE KEY uq_revizior_user_external (organization_link_id, user_uuid),
  UNIQUE KEY uq_revizior_user_internal (organization_link_id, user_id),
  KEY idx_revizior_user_active (user_id, active),
  CONSTRAINT fk_revizior_user_organization
    FOREIGN KEY (organization_link_id) REFERENCES revizior_organization_links(id) ON DELETE RESTRICT,
  CONSTRAINT fk_revizior_user_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizior_idempotency_keys (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_uuid  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operation     VARCHAR(64) NOT NULL,
  key_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state         VARCHAR(16) NOT NULL,
  http_status   SMALLINT UNSIGNED NULL,
  response_json JSON NULL,
  resource_type VARCHAR(32) NULL,
  resource_id   VARCHAR(64) NULL,
  created_at    DATETIME(6) NOT NULL,
  completed_at  DATETIME(6) NULL,
  expires_at    DATETIME(6) NOT NULL,
  UNIQUE KEY uq_revizior_idempotency (subject_uuid, operation, key_hash),
  KEY idx_revizior_idempotency_expiry (expires_at),
  KEY idx_revizior_idempotency_state (state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revizior_security_nonces (
  jti_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  purpose     VARCHAR(16) NOT NULL,
  issuer      VARCHAR(255) NOT NULL,
  subject     VARCHAR(64) NOT NULL,
  expires_at  DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NOT NULL,
  request_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  KEY idx_revizior_nonce_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
