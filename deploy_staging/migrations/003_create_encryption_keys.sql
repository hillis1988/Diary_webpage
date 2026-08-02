-- 003_create_encryption_keys.sql
--
-- Data encryption keys (DEKs) for envelope encryption. Each DEK is sealed with the master
-- key (KEK) held in config/config.php, outside the document root.
--
-- Requirements: 4.1, 4.2 (Special_Category_Data encrypted at rest with AES-256 or stronger).
--
-- Notes:
--   * The newest row with `retired_at IS NULL` is the active key for new writes.
--   * Retired keys are kept so existing rows stay decryptable until re-encrypted; a key row
--     may only be deleted once no encrypted row references it.
--   * Created before diary_entries, cbt_recommendations and milestones because they all
--     carry a `key_id` foreign key onto this table.

CREATE TABLE encryption_keys (
    id          CHAR(26)   NOT NULL,
    wrapped_dek BLOB       NOT NULL,
    wrap_nonce  BINARY(12) NOT NULL,
    created_at  DATETIME   NOT NULL,
    retired_at  DATETIME   NULL,
    PRIMARY KEY (id),
    KEY idx_encryption_keys_active (retired_at, created_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  ROW_FORMAT = DYNAMIC;
