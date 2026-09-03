-- ReviziOR R4: schválená návratová adresa uložená na session.
--
-- „Zpět do ReviziORu" nesmí vycházet z query stringu ani z neověřeného
-- ticketu; ukládá se sem až po kontrole allowlistu originů. NULL = běžná
-- session (standalone login), která odkaz nemá.

SET NAMES utf8mb4;

ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS revizior_return_url VARCHAR(2048) NULL AFTER revoked_at;
