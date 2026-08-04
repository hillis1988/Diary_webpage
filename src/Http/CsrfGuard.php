<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Auth\SessionCookie;
use Diary\Support\Clock;
use Diary\Support\SystemClock;
use InvalidArgumentException;

/**
 * Issues and verifies CSRF tokens.
 *
 * The token is stateless and signed: `issuedAt.mac`, where the MAC covers the issue
 * time and a fingerprint of the session cookie the form was rendered with. That
 * shape is chosen because of where the check sits in the pipeline - the CSRF stage
 * runs *before* session resolution, so it cannot ask a Session object for a stored
 * token. Fingerprinting the raw cookie value needs no database round trip, yet still
 * ties a token to one session: a token minted for somebody else's session fails the
 * MAC check.
 *
 * Consequences worth stating plainly:
 *
 * - the token expires on its own after {@see LIFETIME_MINUTES}, which is what
 *   "stale" means;
 * - signing in or out changes the cookie, so tokens rendered before it stop
 *   verifying. That looks like an expired form, and re-rendering the page fixes it;
 * - verification is one `hash_equals`, so no comparison leaks timing information;
 * - the secret is derived from the master key with a distinct HKDF label, so no
 *   extra secret has to be added to the configuration and the CSRF secret is not the
 *   encryption key.
 *
 * Issuing and verifying are pure functions of (request, clock, secret), so both are
 * unit-testable with a {@see \Diary\Support\FixedClock}.
 */
final class CsrfGuard
{
    /** The form field, and the header for the small amount of progressive JS. */
    public const FIELD_NAME = '_csrf';

    public const HEADER_NAME = 'X-CSRF-Token';

    /**
     * Two hours. Long enough to write a diary entry without racing a timer, short
     * enough that a token left in a browser overnight is not still usable - and
     * comfortably beyond the 30-minute session idle timeout, so an idle session is
     * refused as an ended session rather than as an expired form.
     */
    public const LIFETIME_MINUTES = 120;

    /** Tolerance for a clock that ticked backwards a little; not a validity window. */
    private const FUTURE_SKEW_SECONDS = 60;

    private const HKDF_LABEL = 'diary-csrf-token-v1';

    private const VERSION = 'v1';

    private readonly Clock $clock;

    /**
     * @param string $secret raw HMAC key, at least 32 bytes
     */
    public function __construct(private readonly string $secret, ?Clock $clock = null)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The CSRF signing secret must be at least 32 bytes.');
        }

        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Derive the signing secret from the master encryption key.
     *
     * HKDF with a fixed label gives a key that is independent of the encryption key
     * in every way that matters: recovering it would not decrypt a diary entry, and
     * it costs no new configuration value to deploy.
     *
     * @param string $masterKey 32 raw bytes, as decoded from `encryption.master_key_base64`
     */
    public static function withMasterKey(string $masterKey, ?Clock $clock = null): self
    {
        if (strlen($masterKey) < 32) {
            throw new InvalidArgumentException('The master key must be at least 32 raw bytes.');
        }

        return new self(hash_hkdf('sha256', $masterKey, 32, self::HKDF_LABEL), $clock);
    }

    /**
     * A token for the form about to be rendered, bound to this request's session
     * cookie and to the current time.
     */
    public function issueFor(Request $request): string
    {
        return $this->mint($this->clock->now()->getTimestamp(), $this->binding($request));
    }

    /**
     * Verify the token that arrived with a request. Never throws: a bad token is an
     * ordinary outcome, not an exception.
     */
    public function verify(Request $request): CsrfVerdict
    {
        $presented = $request->formParam(self::FIELD_NAME) ?? $request->header(self::HEADER_NAME);

        if ($presented === null || $presented === '') {
            return CsrfVerdict::Missing;
        }

        $parts = explode('.', $presented);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION || !ctype_digit($parts[1])) {
            return CsrfVerdict::Malformed;
        }

        $issuedAt = (int) $parts[1];
        $expected = $this->mint($issuedAt, $this->binding($request));

        // One constant-time comparison over the whole token: an attacker learns
        // nothing about the MAC from how long the answer took.
        if (!hash_equals($expected, $presented)) {
            return CsrfVerdict::Mismatched;
        }

        // Only now, with the signature trusted, does the timestamp mean anything.
        $age = $this->clock->now()->getTimestamp() - $issuedAt;
        if ($age > self::LIFETIME_MINUTES * 60 || $age < -self::FUTURE_SKEW_SECONDS) {
            return CsrfVerdict::Stale;
        }

        return CsrfVerdict::Valid;
    }

    private function mint(int $issuedAt, string $binding): string
    {
        $message = self::VERSION . '|' . $issuedAt . '|' . $binding;

        return self::VERSION . '.' . $issuedAt . '.' . hash_hmac('sha256', $message, $this->secret);
    }

    /**
     * A fingerprint of the session cookie, so a token cannot be lifted from one
     * session and replayed in another. Hashed rather than used raw so the session
     * token never appears inside a value rendered into a page.
     */
    private function binding(Request $request): string
    {
        return hash('sha256', $request->cookie(SessionCookie::NAME) ?? '');
    }
}
