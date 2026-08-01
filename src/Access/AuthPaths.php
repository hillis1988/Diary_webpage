<?php

declare(strict_types=1);

namespace Diary\Access;

/**
 * The two paths an unauthenticated caller is allowed to reach, and how the
 * redirect to the first of them is built (Requirement 2.6).
 *
 * Stated once, here, so the router, the redirect and the tests cannot disagree
 * about which paths are open. Everything not named here is protected: the
 * allowance is a closed list rather than a pattern, so a new page is protected by
 * default and has to be added deliberately to become public.
 */
final class AuthPaths
{
    public const LOGIN = '/login';
    public const REGISTER = '/register';

    /** The query parameter carrying where the caller was heading. */
    public const RETURN_PARAM = 'next';

    /**
     * A path longer than this is not worth carrying through a redirect; it is
     * dropped rather than truncated, and the caller lands on the login page.
     */
    private const MAX_RETURN_PATH_LENGTH = 512;

    private function __construct()
    {
    }

    /**
     * Whether an anonymous request may proceed to this path.
     *
     * The query string is ignored and a single trailing slash is tolerated, so
     * `/login?next=%2Fcalendar` and `/login/` are the login page too.
     */
    public static function isPublic(string $path): bool
    {
        $normalised = self::normalise($path);

        return $normalised === self::LOGIN || $normalised === self::REGISTER;
    }

    /**
     * Where to send an anonymous request, preserving where it was heading.
     *
     * The intended path is only kept when it is a local path we would have served:
     * it must start with a single slash, so `//evil.example` and
     * `https://evil.example` are dropped rather than turned into an open redirect,
     * and it must contain no control characters, so nothing can be spliced into the
     * `Location` header. An intended path that is itself public is dropped too -
     * bouncing back to the login page after signing in would be pointless.
     */
    public static function loginLocationFor(?string $intendedPath): string
    {
        $returnTo = self::sanitiseReturnPath($intendedPath);

        if ($returnTo === null) {
            return self::LOGIN;
        }

        return self::LOGIN . '?' . self::RETURN_PARAM . '=' . rawurlencode($returnTo);
    }

    /**
     * The intended path as it will be carried, or null when it cannot safely be.
     */
    public static function sanitiseReturnPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (strlen($path) > self::MAX_RETURN_PATH_LENGTH) {
            return null;
        }

        // A relative path only: one leading slash, and no scheme or authority.
        if ($path[0] !== '/' || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        // CR, LF, NUL and friends would let a caller write extra response headers.
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        if (self::isPublic($path)) {
            return null;
        }

        return $path;
    }

    /**
     * The path part, lowercased, without a query string, fragment, or trailing slash.
     */
    private static function normalise(string $path): string
    {
        $withoutQuery = strtok($path, '?#');
        $path = $withoutQuery === false ? '' : $withoutQuery;

        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return strtolower($path === '' ? '/' : $path);
    }
}
