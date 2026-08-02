<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Auth\AuthService;
use Diary\Auth\SecurityContext;
use Diary\Auth\SessionCookie;
use Diary\Support\Clock;
use Diary\Support\Operation;

/**
 * The sign-out route (Requirement 2.4).
 *
 * A single POST, authorised as {@see Operation::signOut()} - the
 * {@see \Diary\Support\OperationKind::ReadDiaryData} kind, which the permission
 * matrix already allows for a viewer context as well as an owner context, so a
 * Viewer can sign themselves out just as an Owner can. An anonymous POST here is
 * redirected to the login page like any other protected request rather than
 * treated as an error - there is no session to end, and that is not a distinct
 * outcome worth its own branch.
 *
 * Ending the session mirrors {@see AccountController::delete()}'s sign-out step
 * exactly: the raw token is read from the cookie (never from the resolved
 * {@see SecurityContext}, which carries no token - only {@see AuthService::signOut()}
 * that takes a {@see \Diary\Auth\SessionId} can end the row), the row is
 * terminated so the same token can never resolve again, and the response clears
 * the cookie with {@see SessionCookie::clearingHeader()} before redirecting to
 * `/login`. A request that arrives with no cookie at all - already signed out,
 * or never signed in - still redirects the same way; {@see AuthService::signOut()}
 * is idempotent, so there is nothing to guard against calling it a second time.
 */
final class LogoutController
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly AuthService $authService,
        private readonly Clock $clock,
    ) {
    }

    public function signOut(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, Operation::signOut($request->pathWithQuery()));
        if ($denied !== null) {
            return $denied;
        }

        $token = SessionCookie::readToken($request->cookies());
        if ($token !== null) {
            $this->authService->signOut($token->id(), $this->clock->now());
        }

        return Response::redirect(AccessControlService::LOGIN_PATH, Decision::REDIRECT_STATUS)
            ->withHeader('Set-Cookie', SessionCookie::clearingHeader());
    }

    /**
     * A denial for a context the matrix refuses, rendered the same way
     * {@see AuthorisationMiddleware} would; null when the operation is allowed
     * and the caller should proceed. In practice {@see Operation::signOut()} is
     * never denied - it only ever allows or redirects an anonymous caller - but
     * the check goes through {@see AccessControlService} rather than being
     * assumed here, matching every other controller in this application.
     */
    private function authorise(SecurityContext $context, Operation $operation): ?Response
    {
        $decision = $this->access->authorise($context, $operation);

        if ($decision->isAllowed()) {
            return null;
        }

        if ($decision->isRedirectToLogin()) {
            return Response::redirect((string) $decision->location(), Decision::REDIRECT_STATUS);
        }

        return StatusPage::response(
            Decision::DENIED_STATUS,
            AuthorisationMiddleware::DENIED_HEADING,
            (string) $decision->message()
        );
    }
}
