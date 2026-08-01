# Implementation Plan: Mental Health Diary

## Overview

Implementation follows the layering in the design: support primitives and schema first, then the encryption layer (because every sensitive write depends on it), then authentication and the authorisation pipeline, then the diary/AI/milestone/calendar/summary features, and finally viewer management, deletion, cron endpoints and deployment hardening.

Stack per the design document: PHP 8.2+, MariaDB via PDO with prepared statements, server-rendered templates, Composer with `vendor/` committed, PHPUnit for unit and integration tests, and Eris for the 5 property-based tests. Each correctness property from the design is implemented by exactly one property test running at least 100 iterations and carrying the `// Feature: mental-health-diary, Property N: ...` comment tag; everything else in the design's original 26-property list is covered by example-based unit or integration tests instead, per the "Scope of the property set" section of design.md. Property tests inject a fast test-only password hasher rather than hashing in the loop, so the whole Property suite runs in a few seconds.

Registration and authentication already have property tests in place (Properties 1-7 below) from before this trim. They are correct and stay as properties; the trim applies going forward to the diary/AI/milestone/calendar/summary/viewer/deletion work.

## Tasks

- [x] 1. Project skeleton and support layer
  - [x] 1.1 Create repository layout, Composer setup and front controller bootstrap
    - Create `public/` (document root) with `index.php` and `assets/app.css`, `assets/app.js`; create `src/`, `templates/`, `config/`, `migrations/`, `tools/`, `tests/{Unit,Property,Integration}/`
    - Add `composer.json` with PSR-4 autoloading for `src/`, PHPUnit and Eris as dev dependencies, commit `vendor/`
    - Add `config/config.example.php` documenting database credentials, master key, AI provider settings, AI enable/disable switch and cron token; keep real `config/config.php` untracked and outside the document root
    - Add `phpunit.xml` with Unit, Property and Integration suites
    - _Requirements: 4.3_

  - [x] 1.2 Implement support value objects and primitives
    - Implement `Clock` interface plus system and fixed test implementations so elapsed time is injectable
    - Implement `Ulid` generator, `Result` type carrying machine-readable error code plus user-facing message
    - Implement `LocalDate`, `YearMonth`, `DateRange` (inclusive, handles inverted and zero-length ranges) and `Operation` descriptors
    - _Requirements: 2.5, 9.1_

  - [x] 1.3 Write unit tests for support primitives
    - Cover date range inclusivity, inverted and zero-length ranges, leap days, month/year boundaries, ULID format
    - _Requirements: 9.1_

- [x] 2. Database schema and migrations
  - [x] 2.1 Write migration SQL for all tables
    - Create numbered migrations for `users`, `sessions`, `diary_entries`, `cbt_recommendations`, `milestones`, `encryption_keys`, `purge_jobs`, `audit_log` exactly as specified in the design data models
    - Include the unique index on `users.email_normalized`, the unique index on `(owner_id, entry_date)`, the unique index on `cbt_recommendations.entry_id`, and indexes on `milestones.milestone_date`
    - _Requirements: 1.2, 4.1, 5.4, 6.4, 8.3_

  - [x] 2.2 Implement migration runner and PDO connection factory
    - Write `tools/migrate.php` applying pending migrations in order and recording applied versions so re-running is safe
    - Implement a PDO factory using config credentials, exceptions on error, emulated prepares disabled
    - _Requirements: 4.1_

  - [x] 2.3 Write integration tests for migrations
    - Assert migrations apply to an empty schema and are re-runnable without error
    - _Requirements: 4.1_

- [x] 3. Encryption at rest
  - [x] 3.1 Implement KeyRing and Crypto
    - Implement `KeyRing` that unwraps DEKs from `encryption_keys` using the master key from config, selects the newest non-retired key as active, and keeps retired keys available for decryption
    - Implement `Crypto::encrypt`/`Crypto::decrypt` using AES-256-GCM with a fresh 12-byte nonce per write and the record id plus table name as additional authenticated data
    - _Requirements: 4.1, 4.2_

  - [x] 3.2 Write property test for encryption at rest
    - **Property 11: Special category data is never stored in plaintext**
    - **Validates: Requirements 4.1, 4.2**

  - [x] 3.3 Implement encrypted payload mapping helpers
    - Encode/decode the JSON payloads for diary entries, milestones and recommendations with `schema_version`, mapping to and from the `key_id`, `nonce`, `payload_ciphertext` columns
    - Surface a decryption/authentication-tag failure as an integrity error that prevents rendering rather than returning altered data
    - _Requirements: 4.1, 4.2_

- [x] 4. Registration and password policy
  - [x] 4.1 Implement PasswordPolicy
    - Enforce minimum 12 characters with at least one letter and at least one digit; `describe()` returns the rejection message text
    - _Requirements: 1.3, 1.5_

  - [x] 4.2 Write property test for the password policy
    - **Property 1: Password policy is exactly as specified**
    - **Validates: Requirements 1.3, 1.5**

  - [x] 4.3 Implement UserRepository and Auth_Service registration
    - Normalise email (trim, lowercase) before the uniqueness check, store `email_display` as typed
    - Validate the password before any write; hash with `password_hash` using Argon2id with bcrypt fallback
    - Create the account with `role = owner` and `data_owner_id` set to itself; reject duplicates with the already-registered message leaving the existing account untouched
    - _Requirements: 1.1, 1.2, 1.3, 1.4_

  - [x] 4.4 Write property test for owner account creation on registration
    - **Property 2: Valid registration creates an Owner_Role account**
    - **Validates: Requirements 1.1**

  - [x] 4.5 Write property test for email uniqueness normalisation
    - **Property 3: Email uniqueness is case- and whitespace-insensitive**
    - **Validates: Requirements 1.2**

  - [x] 4.6 Write property test for password hash storage
    - **Property 4: Passwords are stored only as salted one-way hashes**
    - **Validates: Requirements 1.4**

- [x] 5. Authentication, lockout and sessions
  - [x] 5.1 Implement authentication with lockout and audit logging
    - Verify credentials against the stored hash; return the same incorrect-credentials message for a wrong password and an unregistered email
    - Increment `failed_login_count`; on the fifth consecutive failure set `locked_until = now + 15 minutes`; refuse a locked account without checking the password and with comparable timing; reset the counter and clear the lock on success
    - On success create a session row whose `context_role` and `data_owner_id` are frozen from the account
    - Write sign-in, failure and lockout rows to `audit_log` with no health data
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 5.2 Write property test for authentication outcomes
    - **Property 5: Authentication outcome follows credential validity**
    - **Validates: Requirements 2.1, 2.2**

  - [x] 5.3 Write property test for account lockout
    - **Property 6: Lockout after five consecutive failures lasts fifteen minutes**
    - **Validates: Requirements 2.3**

  - [x] 5.4 Implement session store, resolution and sign-out
    - Issue a 256-bit opaque cookie token, store only its SHA-256 as `sessions.id`; set the cookie `Secure`, `HttpOnly`, `SameSite=Strict`, host-only, no expiry attribute
    - `resolveSession` returns a `SecurityContext` only when `terminated_at IS NULL` and `now - last_activity_at < 30 minutes`, and slides `last_activity_at` forward on success
    - `signOut` sets `terminated_at` so the same token never resolves again; clear the cookie and show the session-ended message on expiry
    - _Requirements: 2.4, 2.5_

  - [x] 5.5 Write property test for session validity
    - **Property 7: A session is valid only while it is neither signed out nor idle for thirty minutes**
    - **Validates: Requirements 2.4, 2.5**

- [x] 6. Checkpoint - authentication foundation
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. HTTP pipeline and transport security
  - [x] 7.1 Implement router, TLS guard, security headers and CSRF middleware
    - Front controller runs middleware in fixed order: HTTPS redirect, security headers, CSRF check, session resolution, authorisation, handler
    - Redirect plaintext HTTP to HTTPS before any handler runs; add `.htaccess` redirect; emit `Strict-Transport-Security`, `Content-Security-Policy` (no inline script, no third-party origins), `X-Content-Type-Options`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` on every response
    - Reject a missing or stale CSRF token with the form-expired message and no write
    - _Requirements: 4.3_

  - [x] 7.2 Implement SessionResolver middleware producing the SecurityContext
    - Resolve the cookie token to `SecurityContext { userId, contextRole, dataOwnerId }`; treat an unresolvable, terminated or idle session as anonymous (fail closed)
    - _Requirements: 2.5, 2.6_

- [x] 8. Access control and home page
  - [x] 8.1 Implement Access_Control_Service with the declarative permission matrix
    - Encode the permission matrix as a single table keyed on operation kind and context role; `authorise` redirects anonymous requests on protected routes to the login page preserving the intended path, denies mutations from a viewer context with the read-only message and a 403, and logs denials
    - `resolveDataOwner` is the only sanctioned source of owner scoping and returns the session's `dataOwnerId`
    - Allow login and registration paths for anonymous requests
    - _Requirements: 2.6, 3.3, 4.4, 5.6, 7.3, 7.5, 10.5_

  - [x] 8.2 Write property test for anonymous redirection
    - **Property 8: Unauthenticated requests are redirected to login**
    - **Validates: Requirements 2.6**

  - [x] 8.3 Write property test for viewer-context immutability
    - **Property 1: A viewer context can never change stored state**
    - **Validates: Requirements 3.3, 5.6, 7.3, 7.5, 10.5**

  - [x] 8.4 Implement navigationFor and the home page
    - `navigationFor` returns exactly the controls permitted by the matrix for the session's context role, omitting diary-entry and milestone creation controls in a viewer context
    - Render the home page with the banner text "Roy Hillis personal diary" and links to the diary entry page, calendar, summary page and milestones
    - _Requirements: 3.1, 3.2, 3.3_

  - [x] 8.5 Write unit tests for role-appropriate navigation
    - Cover anonymous, viewer and owner contexts; assert each gets exactly what `navigationFor` allows and a viewer never sees a diary-entry or milestone creation control
    - **Validates: Requirements 3.2**

  - [x] 8.6 Write integration tests for middleware ordering and headers
    - Assert TLS redirect precedes headers, headers precede session resolution, session resolution precedes authorisation, authorisation precedes the handler; assert no application body is emitted over plaintext HTTP
    - _Requirements: 2.6, 4.3_

- [x] 9. Structured diary entry capture
  - [x] 9.1 Define the question set and diary input validation
    - Define the structured question set as data: mood rating (1-10, required), sleep quality (1-5 ordinal, optional), notable events, thoughts, emotions; the form, validation and AI prompt all read this definition
    - Validate the whole input before any write; reject a missing or out-of-range mood rating with the message naming the mood rating field and preserve submitted answers for redisplay
    - _Requirements: 5.1, 5.2, 5.5_

  - [x] 9.2 Write unit tests for mood rating validation
    - Cover a missing rating, each boundary (0, 1, 10, 11), and non-integer input; assert the whole submission is rejected with no write and the message names the mood rating field
    - **Validates: Requirements 5.2, 5.5**

  - [x] 9.3 Implement DiaryEntryRepository and Diary_Service submission
    - Owner-scoped, encrypted upsert keyed on `(owner_id, entry_date)` inside a transaction so a second submission for a date updates rather than duplicates
    - Implement `findByDate`, `findInRange`, `datesWithEntries`, each taking an `OwnerId` resolved by Access_Control_Service and binding it as a SQL parameter
    - _Requirements: 5.3, 5.4, 4.4_

  - [x] 9.4 Write property test for one entry per date
    - **Property 4: Exactly one entry per date, holding the last accepted submission**
    - **Validates: Requirements 5.3, 5.4, 6.4, 8.2**

  - [x] 9.5 Implement the diary entry page and controller
    - Render the question set as an accessible form, restrict create and modify to an owner context, redisplay the entry with its recommendation after submission
    - _Requirements: 5.1, 5.3, 5.6, 8.2_

- [x] 10. AI CBT feedback on entry
  - [x] 10.1 Implement the FeedbackProvider adapter and prompt builder
    - Provider-agnostic HTTPS adapter requesting strict JSON, 20-second timeout, one retry, throwing `ProviderError` on failure
    - Prompts are pseudonymised: entry content and derived metrics only, never account identifiers, email addresses or names
    - Honour the configuration switch that disables AI entirely
    - _Requirements: 6.1_

  - [x] 10.2 Implement AI_Feedback_Service with shape validation and retry
    - Run after the entry is committed and outside its transaction; accept a response only when it carries exactly one non-empty positive focus and one non-empty suggested change, otherwise record `status = 'failed'` and return `Unavailable`
    - Store the accepted recommendation encrypted and linked to the entry with `provider`, `model`, `attempt_count`, `generated_at`; expose a retry control that re-invokes the provider
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

  - [x] 10.3 Write unit tests for feedback input scoping
    - Assert the prompt sent to the provider contains only the entry's own content and derived metrics, and a snapshot test proves no account identifier, email address or name is ever included
    - **Validates: Requirements 6.1**

  - [x] 10.4 Write unit tests for recommendation shape validation
    - Cover an empty positive focus, an empty suggested change, extra fields, and malformed JSON; each is treated as a failure rather than partially stored
    - **Validates: Requirements 6.2, 6.3**

  - [x] 10.5 Write unit tests for feedback failure isolation
    - Assert a provider timeout or error still commits the diary entry, records `status = 'failed'`, and the retry control re-invokes the provider without duplicating the entry
    - **Validates: Requirements 6.5**

  - [x] 10.6 Implement the shared medical disclaimer partial and feedback rendering
    - Single view partial used by every AI surface; render the recommendation, or the "feedback is temporarily unavailable" notice plus retry control on failure
    - _Requirements: 6.5, 6.6_

- [-] 11. Checkpoint - diary and feedback loop
  - Ensure all tests pass, ask the user if questions arise.

- [x] 12. Significant milestones
  - [x] 12.1 Implement Milestone_Service and repository
    - Owner-scoped encrypted create, update, delete and `inRange`; category restricted to the closed set `medication`, `relationship`, `lifestyle`, `other`
    - Reject a blank or missing description, a missing date, or a category outside the set, naming each offending field and writing nothing
    - Restrict all mutations to an owner context
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

  - [x] 12.2 Write unit tests for milestone operations
    - Cover create, update and delete for each category in the closed set, and confirm `inRange` returns exactly the milestones whose date falls inside the given range
    - **Validates: Requirements 10.1, 10.2, 10.3**

  - [x] 12.3 Write unit tests for invalid milestone input
    - Cover a blank description, a missing description, a missing date and a category outside the closed set; assert each is rejected, names the offending field, and writes nothing
    - **Validates: Requirements 10.4**

  - [x] 12.4 Implement milestone pages and controller
    - List, create, edit and delete views with the category selector; controls hidden and requests denied in a viewer context
    - _Requirements: 10.1, 10.2, 10.3, 10.5_

- [x] 13. Calendar view of history
  - [x] 13.1 Implement calendarMonth and the Calendar_View
    - Build `CalendarMonth` from the session's data owner's entry dates and milestone dates for the month; render a date-based layout with entry indicators and milestone indicators
    - Selecting a date with an entry shows that entry and its recommendation; selecting a date without one shows the no-entry message
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

  - [x] 13.2 Write unit tests for calendar indicators
    - Cover a month with entries, milestones, both, and neither; a date with no entry shows the no-entry message; assert indicators only ever come from the resolved data owner
    - **Validates: Requirements 8.1, 8.3, 8.4, 8.5**

- [x] 14. AI progress summary
  - [x] 14.1 Implement TrendCalculator
    - Deterministic count, mean, minimum, maximum for mood and sleep series, tolerating missing sleep values; direction from a least-squares slope mapped to improving, declining or stable
    - _Requirements: 9.2_

  - [x] 14.2 Write property test for trend metrics
    - **Property 5: Trend metrics equal a reference computation**
    - **Validates: Requirements 9.2**

  - [x] 14.3 Implement SummaryProvider adapter and AI_Summary_Service
    - Gather exactly the data owner's entries and milestones whose dates fall within the inclusive selected range; hand computed metrics to the provider as facts to narrate
    - Check the entry count first: fewer than three yields `InsufficientData` before calling the provider; with three or more a provider failure yields `Unavailable`; otherwise return the summary with metrics
    - Relate trends to in-range milestones in the summary input
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

  - [x] 14.4 Write unit tests for summary input scoping
    - Assert entries and milestones outside the selected range, or belonging to another owner, are never included in what is handed to the provider
    - **Validates: Requirements 9.1, 9.3**

  - [x] 14.5 Write unit tests for summary outcome precedence
    - Cover fewer than 3 entries with a working provider, fewer than 3 entries with a failing provider, and 3+ entries with a failing provider; assert insufficient-data always wins when it applies
    - **Validates: Requirements 9.4, 9.5**

  - [x] 14.6 Implement the summary page with date range selection
    - Range picker, rendered trend metrics for mood and sleep, narrative or the more-entries-needed / temporarily-unavailable message, disclaimer partial rendered whenever the service is invoked
    - _Requirements: 9.1, 9.2, 9.4, 9.5, 9.6_

  - [x] 14.7 Write unit tests for the medical disclaimer
    - Assert the shared disclaimer partial renders on the entry feedback view and the summary page, in every outcome branch of each
    - **Validates: Requirements 6.6, 9.6**

- [ ] 15. Read-only viewer accounts
  - [x] 15.1 Implement viewer creation and revocation
    - `createViewer` from an owner context creates a `viewer` account with `data_owner_id` set to the Primary_User and `status = 'invited'`, issuing a single-use invitation token; the viewer sets a password satisfying the same policy, moving them to `active`
    - `revokeViewer` sets `status = 'revoked'` and terminates that viewer's live sessions immediately, blocking further reads and re-authentication
    - Restrict both operations to an owner context and record them in `audit_log`
    - _Requirements: 7.1, 7.4, 7.5_

  - [x] 15.2 Write unit tests for granting and revoking viewer access
    - Cover invite -> set password -> active, and active -> revoke -> blocked from further reads and re-authentication; assert each step is recorded in `audit_log`
    - **Validates: Requirements 7.1, 7.4**

  - [~] 15.3 Write property test for owner-scoped reads
    - **Property 2: Reads are scoped to the session's data owner**
    - **Validates: Requirements 4.4, 7.2, 8.5**

  - [-] 15.4 Implement viewer management page
    - Owner-only page listing viewers with their status, invite form and revoke control
    - _Requirements: 7.1, 7.4, 7.5_

- [ ] 16. Account deletion and cron endpoints
  - [x] 16.1 Implement PurgeService
    - `requestDeletion` sets `deletion_requested_at`, records a `purge_jobs` row, and purges immediately in one transaction: recommendations, entries, milestones, linked viewer accounts, sessions, then the user row
    - Strip audit rows of references to deleted content; leave the `purge_jobs` row on failure; `runPurgeSlice` retries outstanding jobs idempotently within a bounded limit
    - _Requirements: 4.5_

  - [~] 16.2 Write unit tests for account deletion
    - Assert a purge removes every recommendation, entry, milestone, linked viewer account and session for the owner, strips audit rows of references to the deleted content, and a failed step leaves a `purge_jobs` row for the daily cron to retry
    - **Validates: Requirements 4.5**

  - [~] 16.3 Implement cron endpoints
    - `/cron/purge` (daily), `/cron/sessions` (hourly), `/cron/keys` (on demand), each requiring a shared secret compared in constant time, idempotent, and self-limiting well inside 60 seconds
    - `/cron/keys` re-encrypts a slice of rows onto the current DEK and retires superseded keys only when unreferenced
    - _Requirements: 4.2, 4.5_

  - [~] 16.4 Write integration tests for cron endpoints
    - Reject missing or wrong tokens, verify idempotence and bounded work per run
    - _Requirements: 4.2, 4.5_

- [~] 17. Checkpoint - full feature set
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 18. Deployment hardening and message coverage
  - [~] 18.1 Add deployment artefacts and the transport smoke check script
    - `.htaccess` for the `public/` document root (HTTPS redirect, deny access to dotfiles), documented restrictive permissions for `config/config.php`, and a script asserting TLS 1.2+ negotiation, HSTS, security headers and the HTTP-to-HTTPS redirect against a target host
    - _Requirements: 4.3_

  - [~] 18.2 Write unit tests for fixed content and error catalogue wording
    - Home page banner text, the structured question set, prompt construction snapshots proving no identifiers are included, and the exact wording of every user-facing message in the error catalogue
    - _Requirements: 3.1, 5.1, 1.2, 1.3, 2.2, 2.3, 2.5, 3.3, 5.5, 6.5, 9.4, 9.5, 10.4_

  - [~] 18.3 Write end-to-end integration journeys with a stubbed provider
    - Register, sign in, submit an entry, view feedback, add a milestone, browse the calendar, generate a summary, invite and revoke a viewer, delete the account; assert the unique index prevents duplicate entries under concurrent submission
    - _Requirements: 1.1, 2.1, 5.3, 5.4, 6.1, 8.1, 9.1, 7.1, 7.4, 4.5_

- [~] 19. Final checkpoint
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Only 5 correctness properties remain (see design.md's "Scope of the property set"): viewer-context immutability (8.3), owner-scoped reads (15.3), encryption at rest (3.2, done), one entry per date (9.4), and trend metrics (14.2). Registration and authentication (tasks 4 and 5) keep the property tests already written for them as a pre-existing exception; nothing else in the plan uses a property test.
- Property tests use Eris with at least 100 iterations each and the `// Feature: mental-health-diary, Property N: ...` comment tag; time is injected via `Clock`, AI providers are stubbed, and a fast test-only password hasher stands in for Argon2id, so no property test makes a network call, sleeps, or hashes in the loop
- The state-changing property tests (viewer-context immutability, one entry per date) assert against a full database snapshot so an unexpected write anywhere is caught
- Requirement 4.3 (TLS negotiation) and the qualitative half of Requirement 6.3 are verified by the smoke check script and manual release review rather than by properties
- The signed data processing agreement with the AI provider and the record of processing activities entry are deployment prerequisites outside the scope of these coding tasks

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "2.1"] },
    { "id": 1, "tasks": ["1.2", "2.2"] },
    { "id": 2, "tasks": ["1.3", "2.3", "3.1"] },
    { "id": 3, "tasks": ["3.2", "3.3", "4.1"] },
    { "id": 4, "tasks": ["4.2", "4.3"] },
    { "id": 5, "tasks": ["4.4", "4.5", "4.6", "5.1"] },
    { "id": 6, "tasks": ["5.2", "5.3", "5.4"] },
    { "id": 7, "tasks": ["5.5", "7.1"] },
    { "id": 8, "tasks": ["7.2", "8.1"] },
    { "id": 9, "tasks": ["8.2", "8.4", "9.1"] },
    { "id": 10, "tasks": ["8.3", "8.5", "8.6", "9.2", "9.3"] },
    { "id": 11, "tasks": ["9.4", "9.5", "10.1"] },
    { "id": 12, "tasks": ["10.2", "12.1"] },
    { "id": 13, "tasks": ["10.3", "10.4", "10.5", "10.6", "12.2", "12.3", "12.4"] },
    { "id": 14, "tasks": ["13.1", "14.1"] },
    { "id": 15, "tasks": ["13.2", "14.2", "14.3"] },
    { "id": 16, "tasks": ["14.4", "14.5", "14.6", "15.1"] },
    { "id": 17, "tasks": ["14.7", "15.2", "15.4", "16.1"] },
    { "id": 18, "tasks": ["15.3", "16.2", "16.3", "18.1"] },
    { "id": 19, "tasks": ["16.4", "18.2", "18.3"] }
  ]
}
```
