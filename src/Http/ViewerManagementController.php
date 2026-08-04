<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Access\ViewerAccessService;
use Diary\Auth\SecurityContext;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserStatus;
use Diary\Support\Clock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;
use InvalidArgumentException;

/**
 * The viewer management page (Requirements 7.1, 7.4, 7.5).
 *
 * Owner-only, unlike {@see MilestoneController}'s or {@see CalendarController}'s
 * pages: the list itself is never viewer-readable, so every handler here -
 * including the GET that renders the list - is authorised as
 * {@see \Diary\Support\OperationKind::ManageAccess}, not
 * {@see \Diary\Support\OperationKind::ReadDiaryData}. That is the one difference
 * from the dual-authorisation pattern those controllers use: there, the GET is a
 * read a viewer may reach; here, the GET is denied to a viewer just as the POSTs
 * are (Requirement 7.5).
 *
 * There is no outbound email in this MVP (see {@see ViewerAccessService}'s class
 * doc), so a successful invite redisplays the list with the invitation link shown
 * once, prominently, for the owner to copy - the raw token is never recoverable
 * from the database afterwards.
 */
final class ViewerManagementController
{
    public const HEADING = 'Viewers';

    public const INVITE_PATH = AccessControlService::VIEWERS_PATH . '/invite';

    public const NO_VIEWERS_MESSAGE = 'No viewers have been invited yet.';

    public const EMAIL_FIELD = 'email';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly ViewerAccessService $viewerAccess,
        private readonly CsrfGuard $csrf,
        private readonly Clock $clock,
    ) {
    }

    public function list(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::listOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $owner = $this->access->resolveDataOwner($context);
        $viewers = $this->viewerAccess->viewersFor($owner);

        return Response::html(self::renderList(
            $viewers,
            $this->csrf->issueFor($request),
            null,
            null,
            self::EMAIL_FIELD,
        ));
    }

    public function invite(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, Operation::createViewer($request->pathWithQuery()));
        if ($denied !== null) {
            return $denied;
        }

        $owner = $this->access->resolveDataOwner($context);
        $email = $request->formParam(self::EMAIL_FIELD) ?? '';

        $result = $this->viewerAccess->createViewer($owner, $email, $this->clock);
        $viewers = $this->viewerAccess->viewersFor($owner);

        if ($result->isFailure()) {
            return Response::html(self::renderList(
                $viewers,
                $this->csrf->issueFor($request),
                $result->message(),
                null,
                $email,
            ));
        }

        $invitation = $result->value();
        $invitationLink = self::invitationLink($request, $invitation->token->value());

        return Response::html(self::renderList(
            $viewers,
            $this->csrf->issueFor($request),
            null,
            $invitationLink,
            self::EMAIL_FIELD,
        ));
    }

    /**
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, Operation::revokeViewer($request->pathWithQuery()));
        if ($denied !== null) {
            return $denied;
        }

        $viewerId = self::parseId($params['id'] ?? '');
        if ($viewerId === null) {
            return self::notFound();
        }

        $owner = $this->access->resolveDataOwner($context);
        $result = $this->viewerAccess->revokeViewer($owner, $viewerId, $this->clock);

        if ($result->isFailure()) {
            return self::notFound();
        }

        return Response::redirect(AccessControlService::VIEWERS_PATH, Decision::REDIRECT_STATUS);
    }

    public static function revokePath(UserId $viewerId): string
    {
        return AccessControlService::VIEWERS_PATH . '/' . $viewerId->toString() . '/revoke';
    }

    /**
     * The link the owner copies and shares themselves (there is no outbound
     * email in this MVP): an absolute-path URL to
     * {@see AccessControlService::ACCEPT_INVITATION_PATH}, carrying the raw
     * token as a query parameter. No controller reads this path yet - it is
     * reserved for the invitation-acceptance page - so the token appears here
     * and nowhere the database can be read to recover it again.
     */
    private static function invitationLink(Request $request, string $token): string
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = $request->header('host') ?? '';

        $path = AccessControlService::ACCEPT_INVITATION_PATH . '?token=' . rawurlencode($token);

        return $host === '' ? $path : $scheme . '://' . $host . $path;
    }

    private static function parseId(string $raw): ?UserId
    {
        try {
            return UserId::fromString($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function notFound(): Response
    {
        return StatusPage::response(404, Router::NOT_FOUND_HEADING, Router::NOT_FOUND_MESSAGE);
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

    private static function listOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::ManageAccess, 'viewer.list', $request->pathWithQuery());
    }

    /**
     * @param list<UserAccount> $viewers
     */
    public static function renderList(
        array $viewers,
        string $csrfToken,
        ?string $errorMessage,
        ?string $invitationLink,
        string $emailValue,
    ): string {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');

        $body = $viewers === []
            ? '            <p>' . htmlspecialchars(self::NO_VIEWERS_MESSAGE, ENT_QUOTES, 'UTF-8') . '</p>' . "\n"
            : self::renderViewerList($viewers, $csrfToken);

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
            . self::renderInvitationLink($invitationLink)
            . '        <div class="card">' . "\n"
            . $body
            . '        </div>' . "\n"
            . self::renderInviteForm($csrfToken, $errorMessage, $emailValue)
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /**
     * @param list<UserAccount> $viewers
     */
    private static function renderViewerList(array $viewers, string $csrfToken): string
    {
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

        $items = '';
        foreach ($viewers as $viewer) {
            $safeEmail = htmlspecialchars($viewer->emailDisplay, ENT_QUOTES, 'UTF-8');
            $safeStatus = htmlspecialchars(ucfirst($viewer->status->value), ENT_QUOTES, 'UTF-8');

            $controls = '';
            if ($viewer->status !== UserStatus::Revoked) {
                $safeRevokePath = htmlspecialchars(self::revokePath($viewer->id), ENT_QUOTES, 'UTF-8');
                $controls = '                    <form method="post" action="' . $safeRevokePath . '" class="form-inline">' . "\n"
                    . '                        <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
                    . '                        <button type="submit" class="button button--secondary button--small">Revoke</button>' . "\n"
                    . '                    </form>' . "\n";
            }

            $items .= '                <li class="item-row">' . "\n"
                . '                    <div class="item-row__main">' . "\n"
                . '                        <span>' . $safeEmail . '</span>' . "\n"
                . '                        <span class="badge">' . $safeStatus . '</span>' . "\n"
                . '                    </div>' . "\n"
                . $controls
                . '                </li>' . "\n";
        }

        return '            <ul class="item-list">' . "\n" . $items . '            </ul>' . "\n";
    }

    private static function renderInvitationLink(?string $invitationLink): string
    {
        if ($invitationLink === null) {
            return '';
        }

        $safeLink = htmlspecialchars($invitationLink, ENT_QUOTES, 'UTF-8');

        return '        <div role="alert" class="notice notice--success">' . "\n"
            . '            <p>Invitation created. Share this link with the viewer - it will not be shown again:</p>' . "\n"
            . '            <p><a href="' . $safeLink . '">' . $safeLink . '</a></p>' . "\n"
            . '        </div>' . "\n";
    }

    private static function renderInviteForm(string $csrfToken, ?string $errorMessage, string $emailValue): string
    {
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeEmailValue = htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8');

        $error = $errorMessage === null
            ? ''
            : '            <p role="alert" class="notice notice--error">' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";

        return '        <div class="card">' . "\n"
            . '        <form method="post" action="' . htmlspecialchars(self::INVITE_PATH, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '            <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . $error
            . '            <div class="field-group">' . "\n"
            . '                <label for="' . self::EMAIL_FIELD . '">Viewer\'s email address</label>' . "\n"
            . '                <input type="email" id="' . self::EMAIL_FIELD . '" name="' . self::EMAIL_FIELD . '" value="' . $safeEmailValue . '" required>' . "\n"
            . '            </div>' . "\n"
            . '            <button type="submit" class="button">Invite viewer</button>' . "\n"
            . '        </form>' . "\n"
            . '        </div>' . "\n";
    }
}
