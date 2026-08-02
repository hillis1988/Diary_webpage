-- 005_create_cbt_recommendations.sql
--
-- At most one CBT_Recommendation per Diary_Entry.
--
-- Requirements: 6.1 (generated from the entry), 6.2, 6.3 (one positive focus, one suggested
--               change - stored encrypted), 6.4 (exactly one recommendation per entry),
--               6.5 (failure recorded so it can be retried), 4.1, 4.2 (encrypted at rest).
--
-- Encrypted payload (AES-256-GCM, additional authenticated data = "cbt_recommendations" + id):
--   { "positive_focus": "...", "suggested_change": "...", "schema_version": 1 }
--
-- Notes:
--   * uq_cbt_recommendations_entry enforces Requirement 6.4 at the storage layer, so a retry
--     updates the existing row instead of adding a second recommendation.
--   * The ciphertext columns are nullable because a `failed` row carries no payload, only the
--     bookkeeping needed for the retry control.
--   * `provider` and `model` are the processing trail for the AI data-processor record; they
--     hold no health data.

CREATE TABLE cbt_recommendations (
    id                 CHAR(26)                     NOT NULL,
    entry_id           CHAR(26)                     NOT NULL,
    status             ENUM('generated','failed')   NOT NULL,
    key_id             CHAR(26)                     NULL,
    nonce              BINARY(12)                   NULL,
    payload_ciphertext BLOB                         NULL,
    provider           VARCHAR(100)                 NULL,
    model              VARCHAR(100)                 NULL,
    attempt_count      TINYINT UNSIGNED             NOT NULL DEFAULT 0,
    generated_at       DATETIME                     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cbt_recommendations_entry (entry_id),
    KEY idx_cbt_recommendations_key (key_id),
    CONSTRAINT fk_cbt_recommendations_entry
        FOREIGN KEY (entry_id) REFERENCES diary_entries (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_cbt_recommendations_key
        FOREIGN KEY (key_id) REFERENCES encryption_keys (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  ROW_FORMAT = DYNAMIC;
