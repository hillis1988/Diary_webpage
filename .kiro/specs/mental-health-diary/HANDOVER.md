# Handover — Mental Health Diary spec

Written for whoever picks this up next. Read this before trusting tasks.md's checkboxes at face value — see "Known issue" below.

## Where things stand

**33 of 82 tasks complete.** Full test suite green as of this session: 306 unit tests, 8 property tests (46k+ assertions), 4 integration tests against a real local MariaDB — all passing, ~11 seconds total runtime.

Done and verified this session:
- Auth foundation (registration, login, lockout, sessions, CSRF, security headers, TLS redirect) — tasks 1–7
- Access control service + declarative permission matrix (8.1)
- Home page with role-based navigation (8.4) — built and tested this session
- Navigation unit tests (8.5) — satisfied by the same test file as 8.4, marked complete rather than duplicated

Exists in code but **not yet tested**:
- `src/Diary/QuestionSet.php`, `QuestionDefinition.php`, `DiaryEntryInput.php`, `DiaryValidation.php`, `SubmittedAnswers.php` — the diary question set and input value objects (task 9.1 scope). No `DiaryEntryRepository` or `Diary_Service` yet — that's 9.3, still open.

## Known issue: tasks.md status has been unreliable

Twice this session, tasks were marked `completed` in tasks.md with an `executionHistory` entry pointing to a chat session that is **not this conversation**, while nothing existed on disk for that task (task 7.2 first, then 8.4 a second time). Both times I verified with `grep_search`/`list_directory` before trusting the checkbox, found nothing, and reset the status to `not_started` before actually doing the work.

**Do not trust a `completed` checkbox without grepping for the thing it claims to have built.** Something is writing to this tasks.md file outside of the visible conversation — possibly a parallel session on the same spec. Worth investigating before assuming any status is accurate.

## What's queued next (4 tasks, dispatch-ready, no blocking dependencies between them)

- **8.3** — property test for viewer-context immutability (Property 1 in the trimmed set — see design.md's "Scope of the property set")
- **8.6** — integration tests for middleware ordering (needs the running MariaDB instance; see below)
- **9.2** — unit tests for mood rating validation (tests the untested `DiaryEntryInput`/`DiaryValidation` classes above)
- **9.3** — `DiaryEntryRepository` + `Diary_Service.submitEntry()` — the actual owner-scoped encrypted upsert. This is the next piece of real feature work; everything after task 9 depends on it.

I was mid-way through gathering file context for these four (read Pipeline.php, OwnerId.php, Verdict.php, etc.) but had not yet dispatched subagents when this session ended. No half-finished work exists on disk from that — I only read files, wrote nothing.

## Environment

- **PHP 8.4.22**, not on PATH. Full path: `C:\Users\roy_m\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`. Prepend to `$env:Path` in PowerShell before running `php` or `vendor/bin/phpunit`.
- **MariaDB 12.3.2 installed locally**, root/root. `phpunit.xml` already points `DIARY_TEST_DB_*` env vars at `127.0.0.1:3306` with password `root`. Integration tests create/drop their own throwaway `diary_test_mig_<hex>` schema per run — safe to re-run, never touches a real schema.
- **Production target is IONOS** (managed PHP + MariaDB hosting). Nothing local should assume a different deployment shape — migrations must stay plain MariaDB SQL, no SQLite-only syntax.
- Run the full suite: `php vendor/bin/phpunit` (add PHP to PATH first). Property suite alone: `--testsuite Property` (~3s). Integration alone: `--testsuite Integration`.

## Design decisions the user asked for explicitly (don't relitigate)

1. **Trimmed from 26 property tests to 5.** Original design.md had 26 correctness properties; user said it was "taking far too long for a personal diary." Now only 5 stay as Eris property tests (viewer immutability, owner-scoped reads, encryption at rest, one-entry-per-date, trend metrics) — the rest became example-based unit tests. Full rationale is in design.md's "Scope of the property set" section. **Do not add new property tests for anything outside that list of 5 without checking with the user first.**
2. **Property tests must not hash passwords in the loop.** Argon2id is deliberately slow; property tests inject a fast test-only `PasswordHasher` instead. This is already applied to all 8 existing property tests (confirmed this session — suite runs in ~3s, not minutes). Keep doing this for any new property test that touches registration/auth.
3. **Registration/auth property tests (Properties 1–7 numbering in the test files) predate the trim and were left in place** even though the design's new "Scope" section only lists 5 properties going forward. This is intentional — don't remove them, and don't feel obliged to match their internal numbering to the new design.md numbering; they're inconsistent by design (grandfathered in).

## Uncommitted work

`git status` shows 38 changed files (22 untracked) as of this session's end, on top of 2 existing commits (`b2245a2` "Initial commit", `f35a4f8` "feat: Initialize..."). Nothing has been committed this session. The user hasn't asked for a commit yet — don't commit unprompted, but it's worth surfacing early in the next session since `vendor/` is meant to be committed per the design (IONOS deployment is a plain file copy) and a large uncommitted diff is a risk.

## Style note for whoever continues this

The user pushed back hard, twice, on verbosity and on not stopping when asked. Keep updates short. Verify claims against the actual filesystem/test output before stating them as fact — this spec has already had two false "completed" statuses that weren't caught until someone grepped for the code.
