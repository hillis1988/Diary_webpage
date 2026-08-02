<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\InvitationToken;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\SecurityContext;
use Diary\Auth\SessionCookie;
use Diary\Auth\UserAccount;
use Diary\Auth\UserRepository;
use Diary\Support\Clock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;

/**
 * The invitation-acceptance page (Requirement 7.1): where an invited Viewer sets
 * their own password.
 *
 * Public, like {@see RegistrationController} and the login page: the caller
 * arriving here follows a link an owner shared and holds no session at all yet,
 * so both verbs are authorised as {@see \Diary\Support\OperationKind::ViewAuthPage}
 * and the path itself is listed alongside `/login` and `/register` in
 * {@see \Diary\Access\AuthPaths::isPublic()} - that is what lets an anonymous
 * request reach it at all, rather than being redirected to `/login` first.
 *
 * The token travels as a query parameter on the GET and a hidden field on the
 * POST, never as anything this controller trusts on sight: a missing or
 * malformed token (checked only for shape, via {@see InvitationToken::tryFromString()})
 * shows {@see renderInvalid()} instead of a form, and {@see AuthService} is the
 * single place that decides whether a well-formed token actually matches a live,
 * unexpired invitation - this controller does not duplicate that lookup. A token
 * that turns out not to match, or to have expired, or to have already been used
 * surfaces at submission time as the same {@see AuthService::INVITATION_INVALID_MESSAGE}
 * {@see AuthService::acceptViewerInvitation()} would give any other caller.
 *
 * A successful acceptance signs the new Viewer in immediately, the same way
 * {@see RegistrationController} signs a fresh owner in: the account behind the
 * token is looked up *before* {@see AuthService::acceptViewerInvitation()} runs
 * (that call clears `invitation_token_hash` on success, so the row cannot be
 * found by token afterwards), and its email is then handed to
 * {@see AuthService::authenticate()} with the password that was just accepted,
 * which starts the session and carries it home in a `Set-Cookie` header. That
 * keeps there being exactly one place a session is started from a credential
 * check, rather than a second, parallel way of minting one here.
 */
final class AcceptInvitationController
{
    public const HEADING = 'Set your password';

    public const INVALID_HEADING = 'Invitation link invalid';

    /** The query parameter and hidden form field the token travels as. */
    public const TOKEN_FIELD = 'token';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly AuthService $authService,
        private readonly UserRepository $users,
        private readonly CsrfGuard $csrf,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::viewOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $token = $request->queryParam(self::TOKEN_FIELD) ?? '';

        if (InvitationToken::tryFromString($token) === null) {
            return self::renderInvalid();
        }

        return Response::html(self::renderForm($token, null, [], $this->csrf->issueFor($request)));
    }

    public function submit(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::submitOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $token = $request->formParam(self::TOKEN_FIELD) ?? '';
        $password = $request->formParam(PasswordPolicy::FIELD) ?? '';

        $parsedToken = InvitationToken::tryFromString($token);
        if ($parsedToken === null) {
            return self::renderInvalid();
        }

        // Looked up before AuthService consumes the token: a successful
        // acceptViewerInvitation() call below clears invitation_token_hash, so
        // this is the last point the account can still be found by that hash.
        $account = $this->users->findByInvitationTokenHash($parsedToken->hash());

        $result = $this->authService->acceptViewerInvitation($token, $password, $this->clock->now());

        if ($result->isFailure()) {
            if ($result->hasErrorCode(AuthService::INVITATION_INVALID_ERROR_CODE)) {
                return self::renderInvalid();
            }

            return Response::html(self::renderForm(
                $token,
                $result->message(),
                $result->fieldMessages(),
                $this->csrf->issueFor($request),
            ));
        }

        return $this->signInAndRedirectHome($account, $password);
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    public static function renderForm(
        string $token,
        ?string $summaryMessage,
        array $fieldMessages,
        string $csrfToken,
    ): string {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeTokenField = htmlspecialchars(self::TOKEN_FIELD, ENT_QUOTES, 'UTF-8');
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $safeAction = htmlspecialchars(AccessControlService::ACCEPT_INVITATION_PATH, ENT_QUOTES, 'UTF-8');

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
            . '            <h1 class="app-header__title">' . $safeHeading . '</h1>' . "\n"
            . '        </div>' . "\n"
            . '    </header>' . "\n"
            . '    <main id="main">' . "\n"
            . '        <div class="card">' . "\n"
            . self::renderErrorSummary($summaryMessage, $fieldMessages)
            . '            <form method="post" action="' . $safeAction . '">' . "\n"
            . '                <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . '                <input type="hidden" name="' . $safeTokenField . '" value="' . $safeToken . '">' . "\n"
            . self::renderPasswordField($fieldMessages)
            . '                <button type="submit" class="button">Set password</button>' . "\n"
            . '            </form>' . "\n"
            . '        </div>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    public static function renderInvalidPage(): string
    {
        $safeHeading = htmlspecialchars(self::INVALID_HEADING, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars(AuthService::INVITATION_INVALID_MESSAGE, ENT_QUOTES, 'UTF-8');
        $safeLoginPath = htmlspecialchars(AccessControlService::LOGIN_PATH, ENT_QUOTES, 'UTF-8');

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
            . '            <h1 class="app-header__title">' . $safeHeading . '</h1>' . "\n"
            . '        </div>' . "\n"
            . '    </header>' . "\n"
            . '    <main id="main">' . "\n"
            . '        <div class="card">' . "\n"
            . '            <p>' . $safeMessage . '</p>' . "\n"
            . '            <p><a href="' . $safeLoginPath . '">Sign in</a></p>' . "\n"
            . '        </div>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /**
     * A denial for this operation, rendered the same way
     * {@see AuthorisationMiddleware} would; null when the operation is allowed
     * and the caller should proceed. In practice
     * {@see \Diary\Support\OperationKind::ViewAuthPage} is allowed for every
     * context role, so this never actually denies - it exists so the check is
     * made through {@see AccessControlService} rather than assumed.
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

    /**
     * Acceptance succeeded; sign the new Viewer in exactly as an ordinary
     * sign-in would and send them to the home page.
     *
     * A null account or an unexpected sign-in failure is not expected - the
     * account {@see AuthService::acceptViewerInvitation()} just activated is
     * active and its failure counter starts at zero - but rather than let that
     * surface as a server error, the caller is sent to the login page to sign
     * in for themselves.
     */
    private function signInAndRedirectHome(?UserAccount $account, string $password): Response
    {
        if ($account === null) {
            return Response::redirect(AccessControlService::LOGIN_PATH, Decision::REDIRECT_STATUS);
        }

        $signIn = $this->authService->authenticate($account->emailNormalized, $password, $this->clock->now());

        if ($signIn->isFailure()) {
            return Response::redirect(AccessControlService::LOGIN_PATH, Decision::REDIRECT_STATUS);
        }

        $token = $signIn->value()->issuedToken();
        $headers = $token !== null ? ['Set-Cookie' => SessionCookie::header($token)] : [];

        return Response::redirect('/', Decision::REDIRECT_STATUS, $headers);
    }

    private static function renderInvalid(): Response
    {
        return Response::html(self::renderInvalidPage());
    }

    private static function viewOperation(Request $request): Operation
    {
        return Operation::viewAcceptInvitation($request->pathWithQuery());
    }

    private static function submitOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::ViewAuthPage, 'auth.submit_accept_invitation', $request->pathWithQuery());
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderErrorSummary(?string $summaryMessage, array $fieldMessages): string
    {
        if ($summaryMessage === null || $fieldMessages === []) {
            return '';
        }

        $safeSummary = htmlspecialchars($summaryMessage, ENT_QUOTES, 'UTF-8');

        $items = '';
        foreach ($fieldMessages as $field => $message) {
            $safeField = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
            $items .= '                    <li><a href="#' . $safeField . '">'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</a></li>' . "\n";
        }

        return '            <div role="alert" class="notice notice--error">' . "\n"
            . '                <p>' . $safeSummary . '</p>' . "\n"
            . '                <ul>' . "\n"
            . $items
            . '                </ul>' . "\n"
            . '            </div>' . "\n";
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderPasswordField(array $fieldMessages): string
    {
        $field = PasswordPolicy::FIELD;
        $hintId = $field . '-hint';
        $error = self::renderFieldError($field, $fieldMessages);
        $safeHint = htmlspecialchars(DefaultPasswordPolicy::MESSAGE, ENT_QUOTES, 'UTF-8');
        $describedBy = ' aria-describedby="' . $hintId . ($error === '' ? '' : ' ' . $field . '-error') . '"';

        return '                <div class="field-group">' . "\n"
            . '                    <label for="' . $field . '">Password</label>' . "\n"
            . '                    <input type="password" id="' . $field . '" name="' . $field . '" autocomplete="new-password" required' . $describedBy . '>' . "\n"
            . '                    <p id="' . $hintId . '" class="muted">' . $safeHint . '</p>' . "\n"
            . $error
            . '                </div>' . "\n";
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderFieldError(string $field, array $fieldMessages): string
    {
        if (!isset($fieldMessages[$field])) {
            return '';
        }

        $safeField = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($fieldMessages[$field], ENT_QUOTES, 'UTF-8');

        return '                    <p id="' . $safeField . '-error" class="field-error">' . $safeMessage . '</p>' . "\n";
    }
}
