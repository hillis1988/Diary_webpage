-- 008_create_audit_log.sql
--
-- Security event trail: sign-ins, failures, lockouts, denied operations, viewer grants and
-- revocations, and deletions.
--
-- Requirements: 2.3 (lockout evidence), 7.1, 7.4 (viewer grant and revocation trail),
--               4.5 (deletion trail retained in de-identified form).
--
-- Notes:
--   * This table never stores Diary_Entry or Milestone content, so it needs no encryption and
--     can survive a purge once references to deleted content are stripped.
--   * `actor_user_id` and `target_id` carry no foreign keys for exactly that reason: a purge
--     nulls them rather than cascading the audit row away.
--   * `context_role` includes 'anonymous' because failed sign-ins are recorded before any
--     session exists.
--   * `ip_hash` is a keyed SHA-256 of the caller address; the raw address is never stored.

CREATE TABLE audit_log (
    id            CHAR(26)                                NOT NULL,
    actor_user_id CHAR(26)                                NULL,
    context_role  ENUM('owner','viewer','anonymous')      NOT NULL DEFAULT 'anonymous',
    action        VARCHAR(64)                             NOT NULL,
    target_type   VARCHAR(64)                             NULL,
    target_id     CHAR(26)                                NULL,
    outcome       ENUM('success','failure','denied')      NOT NULL,
    ip_hash       CHAR(64)                                NULL,
    occurred_at   DATETIME                                NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_log_actor (actor_user_id, occurred_at),
    KEY idx_audit_log_action (action, occurred_at),
    KEY idx_audit_log_occurred_at (occurred_at)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  ROW_FORMAT = DYNAMIC;
