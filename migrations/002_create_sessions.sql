-- 002_create_sessions.sql
--
-- Database-backed sessions so sign-out and the 30-minute idle timeout are enforceable
-- server-side on shared hosting.
--
-- Requirements: 2.4 (sign-out invalidates the session), 2.5 (idle timeout),
--               7.3 (context role frozen at sign-in).
--
-- Notes:
--   * `id` is the SHA-256 hex digest of the 256-bit opaque cookie token. The raw token is
--     never stored, so a database disclosure does not yield usable session cookies.
--   * `context_role` and `data_owner_id` are frozen when the session is created and never
--     recomputed from the user row mid-session; this is the single enforcement point for
--     "a viewer context can never write" (Requirement 7.3).
--   * `terminated_at` is set on sign-out, viewer revocation, or password change.

CREATE TABLE sessions (
    id               CHAR(64)               NOT NULL,
    user_id          CHAR(26)               NOT NULL,
    context_role     ENUM('owner','viewer') NOT NULL,
    data_owner_id    CHAR(26)               NOT NULL,
    created_at       DATETIME               NOT NULL,
    last_activity_at DATETIME               NOT NULL,
    terminated_at    DATETIME               NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_data_owner (data_owner_id),
    KEY idx_sessions_expiry (terminated_at, last_activity_at),
    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  ROW_FORMAT = DYNAMIC;
