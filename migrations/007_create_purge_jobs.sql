-- 007_create_purge_jobs.sql
--
-- Retry bookkeeping for account deletion. A confirmed deletion purges immediately in one
-- transaction; if any step fails the job row survives and the daily cron slice retries it,
-- which is what keeps the 30-day guarantee under repeated failure.
--
-- Requirements: 4.5 (permanent deletion of account data within 30 days).
--
-- Notes:
--   * `user_id` deliberately carries no foreign key: the user row is deleted first, and the
--     job must outlive it in order to be retried.
--   * `requested_at` is the anchor for the 30-day deadline.
--   * `last_error` holds a short diagnostic message only; it must never contain diary or
--     milestone content.

CREATE TABLE purge_jobs (
    id            CHAR(26)         NOT NULL,
    user_id       CHAR(26)         NOT NULL,
    requested_at  DATETIME         NOT NULL,
    completed_at  DATETIME         NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error    VARCHAR(1000)    NULL,
    PRIMARY KEY (id),
    KEY idx_purge_jobs_user (user_id),
    KEY idx_purge_jobs_outstanding (completed_at, requested_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  ROW_FORMAT = DYNAMIC;
