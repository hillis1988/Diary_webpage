-- 006_create_milestones.sql
--
-- Significant life milestones, owner-scoped and encrypted.
--
-- Requirements: 10.1 (stored against a date and the account), 4.1, 4.2 (encrypted at rest),
--               8.3 (milestone indicators on the calendar), 9.3 (milestones within a range).
--
-- Encrypted payload (AES-256-GCM, additional authenticated data = "milestones" + id):
--   { "description": "...", "category": "medication|relationship|lifestyle|other",
--     "schema_version": 1 }
--
-- Notes:
--   * The category is inside the ciphertext because a value such as "medication" is itself
--     health information.
--   * Unlike diary entries there is no uniqueness rule on the date: several milestones may
--     fall on one day, so `milestone_date` is a plain index. The composite index serves the
--     owner-scoped calendar and range reads; the single-column index supports maintenance
--     and cron work that sweeps by date.

CREATE TABLE milestones (
    id                 CHAR(26)   NOT NULL,
    owner_id           CHAR(26)   NOT NULL,
    milestone_date     DATE       NOT NULL,
    key_id             CHAR(26)   NOT NULL,
    nonce              BINARY(12) NOT NULL,
    payload_ciphertext BLOB       NOT NULL,
    created_at         DATETIME   NOT NULL,
    updated_at         DATETIME   NOT NULL,
    PRIMARY KEY (id),
    KEY idx_milestones_milestone_date (milestone_date),
    KEY idx_milestones_owner_date (owner_id, milestone_date),
    KEY idx_milestones_key (key_id),
    CONSTRAINT fk_milestones_owner
        FOREIGN KEY (owner_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_milestones_key
        FOREIGN KEY (key_id) REFERENCES encryption_keys (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  ROW_FORMAT = DYNAMIC;
