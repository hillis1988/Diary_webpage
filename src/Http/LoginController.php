<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\AuthPaths;
use Diary\Access\Decision;
use Diary\Auth\AuthService;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionCookie;
use Diary\Support\Clock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;

/**
 * The login page (Requirements 2.1, 2.2, 2.3, 2.6).
 *
 * Both verbs are authorised as {@see OperationKind::ViewAuthPage}: the one kind
 * every context, including anonymous, is allowed (see the permission matrix), so
 * signing in never itself requires being signed in. That matches
 * {@see AuthPaths} and the matrix already in place - this controller adds no
 * rule of its own, it only renders and posts to the page those already treat as
 * public.
 *
 * The `next` query parameter (Requirement 2.6) is round-tripped as a hidden
 * field rather than re-read from the query string on POST: the browser's own
 * form submission carries it, so a sign-in that started from a redirect lands
 * back on the page the caller was heading to. It is sanitised on the way in and
 * again on the way out through {@see AuthPaths::sanitiseReturnPath()}, so a
 * hidden field cannot be used to smuggle an unsafe redirect target any more than
 * the query parameter could.
 *
 * A failed attempt redisplays this same form - not a redirect - with the email
 * address preserved and the password left blank; {@see AuthService::authenticate()}
 * already gives the incorrect-credentials and account-locked outcomes the same
 * message shape, so this controller does nothing further to tell them apart
 * (Requirements 2.2, 2.3). A successful attempt sets the session cookie via
 * {@see SessionCookie::header()} and redirects to the sanitised `next` path, or
 * to `/` when there is none (Requirement 2.1).
 */
final class LoginController
{
    public const HEADING = 'Sign in';

    public const EMAIL_FIELD = AuthService::EMAIL_FIELD;

    public const PASSWORD_FIELD = PasswordPolicy::FIELD;

    /** The hidden field the form round-trips {@see AuthPaths::RETURN_PARAM} through. */
    public const NEXT_FIELD = AuthPaths::RETURN_PARAM;

    public function __construct(
        private readonly AccessControlService $access,
        private readonly AuthService $authService,
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

        $next = AuthPaths::sanitiseReturnPath($request->queryParam(AuthPaths::RETURN_PARAM));

        return Response::html(self::render($this->csrf->issueFor($request), $next, '', null));
    }

    public function submit(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::submitOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $email = $request->formParam(self::EMAIL_FIELD) ?? '';
        $password = $request->formParam(self::PASSWORD_FIELD) ?? '';
        $next = AuthPaths::sanitiseReturnPath($request->formParam(self::NEXT_FIELD));

        $result = $this->authService->authenticate($email, $password, $this->clock->now());

        if ($result->isFailure()) {
            return Response::html(self::render(
                $this->csrf->issueFor($request),
                $next,
                $email,
                $result->message(),
            ));
        }

        /** @var Session $session */
        $session = $result->value();
        $token = $session->issuedToken();

        $location = $next ?? '/';

        $response = Response::redirect($location, Decision::REDIRECT_STATUS);

        if ($token !== null) {
            $response = $response->withHeader('Set-Cookie', SessionCookie::header($token));
        }

        return $response;
    }

    public static function render(
        string $csrfToken,
        ?string $next,
        string $emailValue,
        ?string $errorMessage,
    ): string {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeEmailValue = htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8');

        $error = self::renderError($errorMessage);
        $describedBy = $error === '' ? '' : ' aria-describedby="login-error"';

        $nextField = $next === null
            ? ''
            : '            <input type="hidden" name="' . htmlspecialchars(self::NEXT_FIELD, ENT_QUOTES, 'UTF-8')
                . '" value="' . htmlspecialchars($next, ENT_QUOTES, 'UTF-8') . '">' . "\n";

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
            . $error
            . '            <form method="post" action="' . htmlspecialchars(AccessControlService::LOGIN_PATH, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '                <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . $nextField
            . '                <div class="field-group">' . "\n"
            . '                    <label for="' . self::EMAIL_FIELD . '">Email address</label>' . "\n"
            . '                    <input type="email" id="' . self::EMAIL_FIELD . '" name="' . self::EMAIL_FIELD . '" value="' . $safeEmailValue . '" required autocomplete="username"' . $describedBy . '>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="field-group">' . "\n"
            . '                    <label for="' . self::PASSWORD_FIELD . '">Password</label>' . "\n"
            . '                    <input type="password" id="' . self::PASSWORD_FIELD . '" name="' . self::PASSWORD_FIELD . '" required autocomplete="current-password"' . $describedBy . '>' . "\n"
            . '                </div>' . "\n"
            . '                <button type="submit" class="button">Sign in</button>' . "\n"
            . '            </form>' . "\n"
            . '        </div>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function renderError(?string $errorMessage): string
    {
        if ($errorMessage === null) {
            return '';
        }

        $safeMessage = htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');

        return '            <div role="alert" id="login-error" class="notice notice--error">' . "\n"
            . '                <p>' . $safeMessage . '</p>' . "\n"
            . '            </div>' . "\n";
    }

    /**
     * A denial for a context the matrix refuses, rendered the same way
     * {@see AuthorisationMiddleware} would; null when the operation is allowed
     * and the caller should proceed. In practice the login page allows every
     * context, so this never fires - it is kept for the same reason every other
     * controller in this application keeps it: authorisation is decided once, by
     * {@see AccessControlService}, and never re-decided here.
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

    private static function viewOperation(Request $request): Operation
    {
        return Operation::viewLogin($request->pathWithQuery());
    }

    private static function submitOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::ViewAuthPage, 'auth.login.submit', $request->pathWithQuery());
    }
}
