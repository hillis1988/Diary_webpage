<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Auth\AuthService;
use Diary\Auth\SecurityContext;
use Diary\Auth\SessionCookie;
use Diary\Support\Clock;
use Throwable;

/**
 * Stage 4: turn the session cookie into the {@see SecurityContext} the rest of the
 * request runs under (Requirement 2.5).
 *
 * This stage decides *who* the request is; it never decides what they may do. The
 * authorisation stage below it (task 8.1) is what turns an anonymous context into a
 * redirect to the login page (Requirement 2.6), and controllers read the context
 * rather than the cookie. Splitting it that way means there is exactly one place a
 * cookie is turned into an identity, and exactly one place an identity is turned
 * into a permission.
 *
 * Everything here fails closed, and it does so by construction rather than by a
 * chain of guard clauses:
 *
 * - the attribute is attached on *every* path, so a handler that reads it can never
 *   find it missing and mistake "no session was resolved" for "session resolution
 *   did not run";
 * - no cookie, a malformed cookie, an unknown session id, a signed-out session and
 *   one idle past {@see AuthService::IDLE_TIMEOUT_MINUTES} all produce
 *   {@see SecurityContext::anonymous()}, which carries no user id and no owner
 *   scope;
 * - a storage failure is caught rather than propagated. An unreachable database
 *   makes a request anonymous, which the authorisation stage refuses, instead of
 *   producing a stack trace on a diary page.
 *
 * When a cookie arrived and resolved to nothing, the response also carries
 * {@see SessionCookie::clearingHeader()}: the browser is holding a token that is
 * dead server-side (signed out, timed out, or never existed), and leaving it in
 * place means every later request pays for a lookup that cannot succeed. Clearing
 * it is a courtesy, not the enforcement - termination is recorded against the
 * session row, so the same token would resolve to nothing even if the cookie stayed.
 */
final class SessionResolverMiddleware implements Middleware
{
    /**
     * Where the resolved context lives on the request. Read it through
     * {@see contextOf()} rather than by hand, so a missing attribute cannot be
     * read as anything other than anonymous.
     */
    public const CONTEXT_ATTRIBUTE = 'security.context';

    public function __construct(
        private readonly AuthService $auth,
        private readonly Clock $clock,
    ) {
    }

    /**
     * The context a request is running under.
     *
     * Anonymous when the attribute is absent or holds something unexpected, so a
     * handler reached by a pipeline assembled without this stage is not silently
     * treated as signed in.
     */
    public static function contextOf(Request $request): SecurityContext
    {
        $context = $request->attribute(self::CONTEXT_ATTRIBUTE);

        return $context instanceof SecurityContext ? $context : SecurityContext::anonymous();
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $cookieValue = $request->cookie(SessionCookie::NAME);
        $token = SessionCookie::readToken($request->cookies());

        $context = SecurityContext::anonymous();
        // A cookie we could not turn into a live session is a dead token in the
        // browser; a storage failure is not, so it must not clear anybody's cookie.
        $clearCookie = $cookieValue !== null && $cookieValue !== '';

        if ($token !== null) {
            try {
                $context = $this->auth->resolveSession($token->value(), $this->clock->now())
                    ?? SecurityContext::anonymous();
            } catch (Throwable $failure) {
                // Detail belongs in the server log; the request continues as anonymous.
                error_log('Session resolution failed: ' . $failure->getMessage());
                $clearCookie = false;
            }
        }

        if ($context->isAuthenticated()) {
            $clearCookie = false;
        }

        $response = $next->handle($request->withAttribute(self::CONTEXT_ATTRIBUTE, $context));

        // A handler that set its own Set-Cookie has just issued or removed a session
        // deliberately (sign-in, sign-out); it wins over this courtesy.
        if ($clearCookie && !$response->hasHeader('Set-Cookie')) {
            $response = $response->withHeader('Set-Cookie', SessionCookie::clearingHeader());
        }

        return $response;
    }
}
