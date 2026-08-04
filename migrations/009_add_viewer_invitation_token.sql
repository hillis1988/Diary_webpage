-- 009_add_viewer_invitation_token.sql
--
-- Adds the single-use invitation token an owner-created Viewer account uses to set
-- its own password and move from 'invited' to 'active'.
--
-- Requirements: 7.1 (createViewer issues a single-use invitation token),
--               7.5 (creation restricted to an owner context, enforced by the caller).
--
-- Notes:
--   * Only the SHA-256 hash of the token is stored, mirroring how `sessions.id` stores a
--     token hash rather than the raw value (migrations/002_create_sessions.sql) - a database
--     disclosure yields no usable invitation link.
--   * `invitation_expires_at` bounds how long an unclaimed invitation stays valid.
--   * Both columns are cleared (set back to NULL) once the invitation is accepted, which is
--     what makes the token single-use: a second attempt with the same value finds no row.
--   * The unique key guards against a token hash colliding with another row's; with 256 bits
--     of randomness this is not expected to ever fire, but it costs nothing to enforce.

ALTER TABLE users
    ADD COLUMN invitation_token_hash CHAR(64) NULL AFTER password_hash,
    ADD COLUMN invitation_expires_at DATETIME NULL AFTER invitation_token_hash;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_invitation_token_hash (invitation_token_hash);
