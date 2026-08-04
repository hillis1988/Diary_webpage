# Deployment

Deployment target is IONOS shared hosting under `royhillis.co.uk` (see
`design.md`'s "Deployment topology on IONOS"). This is a plain file copy, not a
build: `vendor/` is committed, there is no Node toolchain, and the document
root is `public/`.

## Remote host

- **SSH target:** `su599253@access-5021062681.webspace-host.com`
- **Remote app path:** `/home/www/diary-app` (your home directory on this
  account is `/home/www`, not the filesystem root)
- The IONOS control panel's document-root setting for `royhillis.co.uk` must
  point at `/home/www/diary-app/public`.
- Authentication is by password prompt (no key pair set up as of writing).
  Never write the password itself into this file or any other tracked file.

## Deploy from Windows via zip + scp

The full process, end to end: zip locally, copy over with `scp`, unzip on the
server, then fix permissions. Every step below was worked out and verified
against the live host, including two mistakes worth knowing about so they
don't get repeated (see "Lessons learned" at the end of this section).

### 1. Zip the workspace, excluding dev-only/secret paths

From the repository root (`c:\dev\Diary_webpage`) in PowerShell:

```powershell
# Stage a clean copy first - Compress-Archive has no exclude flag, so
# robocopy does the filtering before zipping.
robocopy . ..\deploy-stage /E /XD .git .kiro .phpunit.cache "public\preview" /XF "config\config.php" *.log

Compress-Archive -Path ..\deploy-stage\* -DestinationPath ..\diary-app.zip -Force
```

What's excluded and why:
- `.git`, `.kiro` - no reason to ship history or specs to the server
- `.phpunit.cache`, `public\preview`, `*.log` - dev-only artefacts
- `config\config.php` - **never overwrite the live one.** It holds the real
  database credentials and master encryption key; the local copy (if you even
  have one) must never replace what's already on the server.

`vendor\` is deliberately **not** excluded - it's committed and shipped as-is
since IONOS shared hosting may not run Composer.

### 2. Copy the zip to the server

```powershell
scp -O ..\diary-app.zip su599253@access-5021062681.webspace-host.com:/home/www/
```

The `-O` flag is required. Windows' bundled OpenSSH `scp.exe` defaults to the
SFTP protocol, which this host chroots to the home directory - so a
destination path like `/home/www/` resolves as `/home/www/home/www/` and
fails with `No such file or directory`. `-O` forces the legacy SCP protocol,
which runs through the normal (unchrooted) shell path instead and works
correctly. This will prompt for the account password.

### 3. SSH in and unzip into its own folder

```bash
ssh su599253@access-5021062681.webspace-host.com
cd /home/www
unzip -o diary-app.zip -d diary-app
rm diary-app.zip
```

The `-d diary-app` is important - it tells `unzip` to extract *into* a
`diary-app` subfolder, keeping the app self-contained inside
`/home/www/diary-app` rather than spilling every file loose into the home
directory (`/home/www` also holds `.ssh`, `.bash_history`, etc., which should
stay untouched).

Sanity-check the result before moving on:

```bash
ls -la /home/www/diary-app/public/index.php
ls -la /home/www/diary-app/vendor/autoload.php
```

Both should show a real file with a non-zero size. If either is missing, the
zip/copy/unzip went wrong somewhere - redo steps 1-3 rather than patching
around it.

### 4. Fix permissions

A fresh unzip generally leaves files world-readable and, depending on the zip
tool, sometimes leaves folders without their "execute" bit set - which blocks
PHP (and everything else) from looking inside them at all, and can present as
a 500 Internal Server Error with no obvious cause. Run this sweep every time
after unzipping:

```bash
# Every folder: owner can read/write/enter it; everyone else can enter/read but not write
find /home/www/diary-app -type d -exec chmod 755 {} \;

# Every ordinary file: owner can read/write; everyone else can only read
find /home/www/diary-app -type f -exec chmod 644 {} \;

# config.php is the one exception - lock it down to owner-only, no group/world access at all
chmod 600 /home/www/diary-app/config/config.php
```

Order matters: the file sweep (`644`) would otherwise leave `config.php` at
`644` too, so `config.php` is re-locked to `600` *after* the broad sweep, not
before.

Verify the folder-execute fix worked (this was the specific symptom seen
during the first deploy - `find`/PHP unable to descend into `vendor/`
subfolders):

```bash
ls -la /home/www/diary-app/vendor/sebastian/
```

You should see a normal folder listing, not `Permission denied`.

### 5. Point the domain at the right folder

In the IONOS control panel (not over SSH), set the document root for
`royhillis.co.uk` to:

```
/home/www/diary-app/public
```

Everything else in `diary-app` (`src`, `vendor`, `config`, etc.) must stay
above the document root and unreachable directly by a browser - that's the
whole point of `config/config.php` living outside `public/` (see "Layout on
the host" below).

### 6. Smoke-check the live site

See "Transport security smoke check" below, and just load the site in a
browser to confirm it's serving pages rather than a 500/404.

### Lessons learned (read before repeating this process)

- **Don't re-run the folder-creation/move cleanup a second time.** If
  `diary-app` already contains the app correctly, re-running a script that
  does `rm -rf diary-app; mkdir diary-app; mv ... diary-app/` will delete the
  good copy and then fail to move anything (because the source files no
  longer exist loose in `/home/www` - they're already inside the folder that
  just got deleted). Always check `ls -la /home/www` and `ls -la
  /home/www/diary-app` first to see what state things are actually in before
  running any cleanup command again.
- **The permission sweep (step 4) is not optional and must be run every
  deploy.** A single `chmod 600` on `config.php` alone is not enough - the
  first live attempt after a fresh unzip failed with a 500 error because
  several `vendor/` subfolders weren't executable, which blocks PHP from
  loading its own libraries. Run the full three-command sweep every time,
  not just the `config.php` line.
- **Silence after `chmod` means success**, not failure - it only prints
  something when there's an error. Always follow a `chmod` with an `ls -la`
  to confirm the change actually took effect.

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
