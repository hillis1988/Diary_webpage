-- 004_create_diary_entries.sql
--
-- One Diary_Entry per owner per date. The whole answer set is encrypted as a single JSON
-- payload; only routing metadata (owner, date, timestamps) stays in cleartext.
--
-- Requirements: 4.1, 4.2 (encrypted at rest), 4.4 (owner scoping), 5.3, 5.4 (exactly one
--               entry per date), 8.1, 8.3, 8.5 (calendar and range lookups).
--
-- Encrypted payload (AES-256-GCM, additional authenticated data = "diary_entries" + id):
--   { "mood_rating": 1-10, "sleep_quality": 1-5|null, "events": "...",
--     "thoughts": "...", "emotions": "...", "schema_version": 1 }
--
-- Notes:
--   * uq_diary_entries_owner_date is what makes the upsert in Diary_Service safe under
--     concurrent submission: a duplicate for the same date updates rather than inserts.
--   * The same unique key serves the calendar month and date-range reads, so no extra
--     index on `entry_date` alone is needed for owner-scoped queries.
--   * `key_id` uses ON DELETE RESTRICT so a key can never be dropped while rows need it.

CREATE TABLE diary_entries (
    id                 CHAR(26)   NOT NULL,
    owner_id           CHAR(26)   NOT NULL,
    entry_date         DATE       NOT NULL,
    key_id             CHAR(26)   NOT NULL,
    nonce              BINARY(12) NOT NULL,
    payload_ciphertext BLOB       NOT NULL,
    created_at         DATETIME   NOT NULL,
    updated_at         DATETIME   NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_diary_entries_owner_date (owner_id, entry_date),
    KEY idx_diary_entries_key (key_id),
    CONSTRAINT fk_diary_entries_owner
        FOREIGN KEY (owner_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_diary_entries_key
        FOREIGN KEY (key_id) REFERENCES encryption_keys (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  ROW_FORMAT = DYNAMIC;
