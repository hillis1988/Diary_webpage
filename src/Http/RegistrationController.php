<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\SecurityContext;
use Diary\Auth\SessionCookie;
use Diary\Support\Clock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;

/**
 * The registration page (Requirements 1.1 to 1.5).
 *
 * Both verbs are authorised as {@see OperationKind::ViewAuthPage}, the one kind
 * the permission matrix allows for every context role including anonymous
 * (Requirement 2.6's counterpart: this is one of the two pages an anonymous
 * caller must always be able to reach). The authorisation call here mirrors
 * {@see DiaryEntryController} and {@see MilestoneController} rather than relying
 * solely on the pipeline's default resolver, so this controller's own notion of
 * what it needs matches {@see AccessControlService} even if it were ever wired
 * up differently.
 *
 * A rejected submission is redisplayed on this same response - not a redirect -
 * with the submitted email preserved and the field message
 * ({@see AuthService::EMAIL_TAKEN_MESSAGE} attached to the email field, or the
 * password policy description attached to the password field) shown beside its
 * field. The password itself is never echoed back.
 *
 * A successful registration signs the new owner in immediately, the same way a
 * successful {@see AuthService::authenticate()} call would: the freshly
 * registered credentials are handed straight to `authenticate`, and the session
 * it starts is carried home in a `Set-Cookie` header built the same way
 * {@see SessionCookie::header()} builds one after an ordinary sign-in. That
 * keeps there being exactly one place a session is started from a credential
 * check, rather than a second, parallel way of minting one here. The redirect
 * that follows lands on the home page, not the login page - registering is
 * meant to be the whole of "getting in", not a detour through signing in again.
 */
final class RegistrationController
{
    public const HEADING = 'Create your account';

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

        return Response::html(self::render('', null, [], $this->csrf->issueFor($request)));
    }

    public function submit(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::submitOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $email = $request->formParam(AuthService::EMAIL_FIELD) ?? '';
        $password = $request->formParam(PasswordPolicy::FIELD) ?? '';

        $result = $this->authService->register($email, $password);

        if ($result->isFailure()) {
            return Response::html(self::render(
                $email,
                $result->message(),
                $result->fieldMessages(),
                $this->csrf->issueFor($request),
            ));
        }

        return $this->signInAndRedirectHome($email, $password);
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    public static function render(
        string $email,
        ?string $summaryMessage,
        array $fieldMessages,
        string $csrfToken,
    ): string {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '    <meta charset="utf-8">' . "\n"
            . '    <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '    <title>' . $safeHeading . '</title>' . "\n"
            . '    <link rel="stylesheet" href="/assets/app.css">' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '    <main id="main">' . "\n"
            . '        <p><a href="' . AccessControlService::LOGIN_PATH . '">Already have an account? Sign in</a></p>' . "\n"
            . '        <h1>' . $safeHeading . '</h1>' . "\n"
            . self::renderErrorSummary($summaryMessage, $fieldMessages)
            . '        <form method="post" action="' . AccessControlService::REGISTER_PATH . '">' . "\n"
            . '            <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . self::renderEmailField($email, $fieldMessages)
            . self::renderPasswordField($fieldMessages)
            . '            <button type="submit">Create account</button>' . "\n"
            . '        </form>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /**
     * A denial for this operation, rendered the same way
     * {@see AuthorisationMiddleware} would; null when the operation is allowed
     * and the caller should proceed. In practice {@see OperationKind::ViewAuthPage}
     * is allowed for every context role, so this never actually denies - it exists
     * so the check is made through {@see AccessControlService} rather than assumed.
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
     * Register succeeded; sign the new owner in exactly as an ordinary sign-in
     * would and send them to the home page.
     *
     * A failure here is not expected - the account {@see AuthService::register()}
     * just created is active and its failure counter starts at zero - but rather
     * than let an unexpected refusal surface as a server error, the new owner is
     * sent to the login page to sign in for themselves.
     */
    private function signInAndRedirectHome(string $email, string $password): Response
    {
        $signIn = $this->authService->authenticate($email, $password, $this->clock->now());

        if ($signIn->isFailure()) {
            return Response::redirect(AccessControlService::LOGIN_PATH, Decision::REDIRECT_STATUS);
        }

        $token = $signIn->value()->issuedToken();
        $headers = $token !== null ? ['Set-Cookie' => SessionCookie::header($token)] : [];

        return Response::redirect('/', Decision::REDIRECT_STATUS, $headers);
    }

    private static function viewOperation(Request $request): Operation
    {
        return Operation::viewRegister($request->pathWithQuery());
    }

    private static function submitOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::ViewAuthPage, 'auth.submit_register', $request->pathWithQuery());
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
            $items .= '                <li><a href="#' . $safeField . '">'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</a></li>' . "\n";
        }

        return '        <div role="alert">' . "\n"
            . '            <p>' . $safeSummary . '</p>' . "\n"
            . '            <ul>' . "\n"
            . $items
            . '            </ul>' . "\n"
            . '        </div>' . "\n";
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderEmailField(string $email, array $fieldMessages): string
    {
        $field = AuthService::EMAIL_FIELD;
        $safeValue = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $error = self::renderFieldError($field, $fieldMessages);
        $describedBy = $error === '' ? '' : ' aria-describedby="' . $field . '-error"';

        return '            <div>' . "\n"
            . '                <label for="' . $field . '">Email address</label>' . "\n"
            . '                <input type="email" id="' . $field . '" name="' . $field . '" value="' . $safeValue . '" autocomplete="email" required' . $describedBy . '>' . "\n"
            . $error
            . '            </div>' . "\n";
    }

    /**
     * The password itself is never preserved on redisplay: only its hint (the
     * policy description, Requirement 1.3) and its field error, if any.
     *
     * @param array<string, string> $fieldMessages
     */
    private static function renderPasswordField(array $fieldMessages): string
    {
        $field = PasswordPolicy::FIELD;
        $hintId = $field . '-hint';
        $error = self::renderFieldError($field, $fieldMessages);
        $safeHint = htmlspecialchars(DefaultPasswordPolicy::MESSAGE, ENT_QUOTES, 'UTF-8');
        $describedBy = ' aria-describedby="' . $hintId . ($error === '' ? '' : ' ' . $field . '-error') . '"';

        return '            <div>' . "\n"
            . '                <label for="' . $field . '">Password</label>' . "\n"
            . '                <input type="password" id="' . $field . '" name="' . $field . '" autocomplete="new-password" required' . $describedBy . '>' . "\n"
            . '                <p id="' . $hintId . '">' . $safeHint . '</p>' . "\n"
            . $error
            . '            </div>' . "\n";
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

        return '                <p id="' . $safeField . '-error">' . $safeMessage . '</p>' . "\n";
    }
}
