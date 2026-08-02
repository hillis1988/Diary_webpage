# Deployment

Deployment target is IONOS shared hosting under `royhillis.co.uk` (see
`design.md`'s "Deployment topology on IONOS"). This is a plain file copy, not a
build: `vendor/` is committed, there is no Node toolchain, and the document
root is `public/`.

## Layout on the host

```
public/            <- document root (index.php, assets/)
src/, templates/   <- above the document root, not web-accessible
config/config.php  <- above the document root, MUST NOT be inside public/
migrations/, tools/ <- above the document root
```

`config/` sits outside `public/` specifically so a web server
misconfiguration cannot serve the encryption key or database credentials
(Requirement 4.3, 4.1). Point the domain at the repository root and configure
`public/` as the document root, per IONOS's support for subdirectory document
roots.

## config/config.php

Copy `config/config.example.php` to `config/config.php` and fill in the real
values (database credentials, the base64 master encryption key, the AI
provider's API key, the cron shared secret).

- **MUST NOT be committed.** `.gitignore` already excludes `/config/config.php`;
  double-check `git status` shows it as untracked after creating it on a new
  host.
- **MUST stay outside the document root.** It lives in `config/`, a sibling of
  `public/`, never inside `public/`.
- **SHOULD have restrictive permissions.** On the host:

  ```
  chmod 600 config/config.php
  chown <web-server-user>:<web-server-group> config/config.php
  ```

  0600 means only the owning user (the PHP process, i.e. the web server user)
  can read or write the file at all - no group or world access. Losing this
  file makes every stored diary entry unreadable; leaking it makes every
  stored diary entry readable, so it is worth getting the ownership right
  rather than falling back to a world-readable file "to make it work".

## Transport security smoke check

After deploying (or after any change to `.htaccess`, the TLS certificate, or
the security headers), run the smoke check script by hand against the live
host:

```
php tools/smoke_check_transport.php royhillis.co.uk
```

It asserts, against the real deployed host:

1. An HTTPS connection negotiates TLS 1.2 or later.
2. The response carries `Strict-Transport-Security`,
   `Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy` and
   `X-Frame-Options` with acceptable values.
3. A plaintext HTTP request redirects to the same host over HTTPS.

It prints a pass/fail line per check and exits non-zero if anything failed, so
it can gate a release. This is a manual, post-deploy check - it is not part of
`composer test` because it makes real network calls against a live host, which
nothing else in this project's test suite does. Requirement 4.3's TLS
negotiation is verified here and by manual release review rather than by a
unit or property test (see `design.md`, "Scope of the property set").

## public/.htaccess

`public/.htaccess` is the server-level half of the transport security rule
(`HttpsRedirectMiddleware` is the application-level half): it redirects
plaintext HTTP to HTTPS via `mod_rewrite`, denies access to dotfiles, disables
directory listings, and repeats the static security headers via
`mod_headers` so a file served directly by Apache (not through `index.php`)
still carries them. It is checked into the repository and deploys with the
rest of `public/`; if IONOS ever migrates the site off Apache, this file (and
the Apache-specific directives inside it) would need replacing with the
equivalent web-server configuration, but the application-level guards in
`src/Http/` are unaffected either way.
