<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Auth\AuthService;
use Diary\Auth\SecurityContext;
use Diary\Auth\SessionCookie;
use Diary\Storage\PurgeService;
use Diary\Support\Clock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;

/**
 * The account deletion page (Requirement 4.5).
 *
 * Owner-only, like {@see ViewerManagementController}: both the confirmation page
 * (the GET) and the deletion itself (the POST) are authorised as
 * {@see OperationKind::ManageAccess}, so a viewer context is denied the page
 * outright rather than merely being denied the button on it. Both verbs share
 * one path, {@see AccessControlService::ACCOUNT_DELETE_PATH}, the same split
 * {@see LoginController} uses for `/login`: the GET renders, the POST writes.
 *
 * The "confirmation step" the task calls for is this page itself, not a second
 * page beyond it: {@see showConfirmation()} renders the warning and the one form
 * that actually deletes, so nothing is destroyed on the request that merely opens
 * this page - only the CSRF-protected POST does that, exactly as
 * {@see MilestoneController::delete()}'s pattern of "the GET only ever
 * displays, the POST is the only route to a write" already establishes
 * elsewhere in this codebase.
 *
 * {@see delete()} calls {@see PurgeService::requestDeletion()} for the session's
 * data owner - never for the signed-in user id, which would let a viewer session
 * delete an account it does not own even if the matrix's `deny` cell were ever
 * bypassed - then ends the session exactly as a sign-out would
 * ({@see AuthService::signOut()} plus {@see SessionCookie::clearingHeader()}) and
 * redirects to the login page. There is no session left to resolve on the next
 * request either way: the purge deletes the `sessions` row itself, and the
 * explicit sign-out here is what makes that immediate for the connection that
 * requested it and keeps the outgoing cookie cleared, rather than leaving a
 * cookie in the browser that would resolve to nothing anyway.
 *
 * Deletion is treated as unconditional once authorised: whether
 * {@see PurgeService::requestDeletion()}'s immediate purge attempt fully
 * completes or leaves a `purge_jobs` row for the daily cron to retry
 * (design.md's error-catalogue row for a failed purge step), the request is
 * told the same thing - the deletion is confirmed, because from the caller's
 * side the account is already inaccessible either way: `deletion_requested_at`
 * is set unconditionally by {@see PurgeService::requestDeletion()} before the
 * purge is attempted, and the session this request signs out of is the same
 * session {@see AccessControlService::resolveDataOwner()} scoped the deletion to.
 */
final class AccountController
{
    public const HEADING = 'Delete account';

    /**
     * The plain, non-technical warning shown before the irreversible action -
     * this codebase's error-catalogue tone applied to a confirmation rather than
     * a rejection.
     */
    public const WARNING_MESSAGE = 'Deleting your account permanently removes your diary entries, '
        . 'milestones, recommendations, and any viewer accounts you have invited. This cannot be undone.';

    public const CONFIRM_BUTTON_LABEL = 'Delete my account permanently';

    /**
     * The query parameter carried on the redirect to the login page, so that
     * page (task 20.1's LoginController) can render a confirmation message
     * rather than the ordinary sign-in form having nothing to say about why the
     * caller landed there signed out.
     */
    public const DELETED_PARAM = 'deleted';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly PurgeService $purgeService,
        private readonly AuthService $authService,
        private readonly CsrfGuard $csrf,
        private readonly Clock $clock,
    ) {
    }

    public function showConfirmation(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::viewOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        return Response::html(self::renderConfirmation($this->csrf->issueFor($request)));
    }

    public function delete(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, Operation::deleteAccount($request->pathWithQuery()));
        if ($denied !== null) {
            return $denied;
        }

        $owner = $this->access->resolveDataOwner($context);
        $this->purgeService->requestDeletion($owner->toUserId(), $this->clock);

        // Mirrors sign-out (Requirement 2.4): end the session row itself - which
        // the purge above has very likely already deleted along with the rest of
        // the account, but ending it explicitly here is what is safe to rely on
        // regardless of how far the purge got - and clear the cookie the browser
        // is holding.
        $token = SessionCookie::readToken($request->cookies());
        if ($token !== null) {
            $this->authService->signOut($token->id(), $this->clock->now());
        }

        return Response::redirect(self::deletionConfirmedLocation(), Decision::REDIRECT_STATUS)
            ->withHeader('Set-Cookie', SessionCookie::clearingHeader());
    }

    private static function deletionConfirmedLocation(): string
    {
        return AccessControlService::LOGIN_PATH . '?' . self::DELETED_PARAM . '=1';
    }

    private static function viewOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::ManageAccess, 'account.view_delete_form', $request->pathWithQuery());
    }

    /**
     * A denial for a non-owner context, rendered the same way
     * {@see AuthorisationMiddleware} would; null when the operation is allowed
     * and the caller should proceed.
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

    public static function renderConfirmation(string $csrfToken): string
    {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');
        $safeWarning = htmlspecialchars(self::WARNING_MESSAGE, ENT_QUOTES, 'UTF-8');
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeAction = htmlspecialchars(AccessControlService::ACCOUNT_DELETE_PATH, ENT_QUOTES, 'UTF-8');
        $safeButtonLabel = htmlspecialchars(self::CONFIRM_BUTTON_LABEL, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '    <meta charset="utf-8">' . "\n"
            . '    <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '    <title>' . $safeHeading . '</title>' . "\n"
            . '    <link rel="stylesheet" href="/assets/app.css">' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '    <header class="app-header">' . "\n"
            . '        <div class="app-header__bar">' . "\n"
            . '            <a class="app-header__back" href="/">Home</a>' . "\n"
            . '            <h1 class="app-header__title">' . $safeHeading . '</h1>' . "\n"
            . '        </div>' . "\n"
            . '    </header>' . "\n"
            . '    <main id="main">' . "\n"
            . '        <div class="card">' . "\n"
            . '            <div role="alert" class="notice notice--error">' . "\n"
            . '                <p>' . $safeWarning . '</p>' . "\n"
            . '            </div>' . "\n"
            . '            <form method="post" action="' . $safeAction . '">' . "\n"
            . '                <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . '                <button type="submit" class="button">' . $safeButtonLabel . '</button>' . "\n"
            . '            </form>' . "\n"
            . '        </div>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }
}
