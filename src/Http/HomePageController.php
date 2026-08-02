<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\NavigationItem;

/**
 * The home page (Requirements 3.1, 3.2).
 *
 * Reached only through the pipeline, so by the time this runs an anonymous
 * request has already been redirected to the login page by the authorisation
 * stage; this controller renders for an authenticated context only. It asks
 * {@see AccessControlService::navigationFor()} for the controls and renders
 * exactly that list - there is no second decision here about what a viewer may
 * see (Requirement 3.3).
 *
 * The sign-out control (Requirement 2.4) is the one thing on this page that is
 * not one of {@see AccessControlService::navigationFor()}'s candidates: every
 * authenticated context, owner or viewer alike, may sign out, which is not a row
 * the permission matrix needs a cell for the way the four navigation
 * destinations do. It is rendered whenever a CSRF token is available - which
 * {@see show()} only issues for an authenticated context, mirroring how
 * `navigationFor()` itself hands back nothing for an anonymous one - as a small
 * POST form, the same CSRF-protected button-in-a-form pattern
 * {@see MilestoneController::renderMilestoneList()} uses for its delete control.
 */
final class HomePageController
{
    /** Requirement 3.1: the exact banner text. */
    public const BANNER = 'Roy Hillis personal diary';

    public const SIGN_OUT_LABEL = 'Sign out';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly CsrfGuard $csrf,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $csrfToken = $context->isAuthenticated() ? $this->csrf->issueFor($request) : null;

        return Response::html(self::render($this->access->navigationFor($context), $csrfToken));
    }

    /**
     * @param list<NavigationItem> $navigation
     * @param string|null $signOutCsrfToken a CSRF token to render the sign-out
     *                                      form with, or null to omit it - null
     *                                      for an anonymous context, since there
     *                                      is no session to end
     */
    public static function render(array $navigation, ?string $signOutCsrfToken = null): string
    {
        $safeBanner = htmlspecialchars(self::BANNER, ENT_QUOTES, 'UTF-8');

        $links = '';
        foreach ($navigation as $item) {
            $links .= '                <li><a href="' . htmlspecialchars($item->path, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($item->label, ENT_QUOTES, 'UTF-8') . '</a></li>' . "\n";
        }

        $signOutForm = $signOutCsrfToken === null ? '' : self::renderSignOutForm($signOutCsrfToken);

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '    <meta charset="utf-8">' . "\n"
            . '    <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '    <title>' . $safeBanner . '</title>' . "\n"
            . '    <link rel="stylesheet" href="/assets/app.css">' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '    <main id="main">' . "\n"
            . '        <h1>' . $safeBanner . '</h1>' . "\n"
            . '        <nav aria-label="Main">' . "\n"
            . '            <ul>' . "\n"
            . $links
            . '            </ul>' . "\n"
            . '        </nav>' . "\n"
            . $signOutForm
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function renderSignOutForm(string $csrfToken): string
    {
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeAction = htmlspecialchars(AccessControlService::LOGOUT_PATH, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars(self::SIGN_OUT_LABEL, ENT_QUOTES, 'UTF-8');

        return '        <form method="post" action="' . $safeAction . '">' . "\n"
            . '            <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . '            <button type="submit">' . $safeLabel . '</button>' . "\n"
            . '        </form>' . "\n";
    }
}
