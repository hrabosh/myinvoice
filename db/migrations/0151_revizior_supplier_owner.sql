-- ReviziOR managed mode: tenantová role vlastníka firmy.
--
-- `supplier_owner` je výhradně per-supplier role. Nikdy se neukládá do
-- `users.role` a nesmí se interpretovat jako globální `admin`.

SET NAMES utf8mb4;

ALTER TABLE user_suppliers
  MODIFY COLUMN IF EXISTS role
    ENUM('supplier_owner','accountant','readonly') NULL DEFAULT NULL
    COMMENT 'per-supplier override; supplier_owner není globální admin; NULL = zdědit globální users.role.';
