-- ReviziOR R2: kanonický otisk posledního authoritative user payloadu.
--
-- Slouží k idempotentnímu user upsertu a k rozlišení skutečné změny od
-- opakovaného doručení stejného snapshotu. NULL zachovává kompatibilitu
-- ownerů založených už organization provisioningem bez user timestampu.

SET NAMES utf8mb4;

ALTER TABLE revizior_user_links
  ADD COLUMN IF NOT EXISTS payload_hash
    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
    AFTER supplier_role;
