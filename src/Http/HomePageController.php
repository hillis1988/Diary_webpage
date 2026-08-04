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
    /**
     * A decorative icon per navigation label, purely presentational: the
     * fallback (no icon) for any label this map does not recognise still
     * renders a perfectly usable link, so an unmapped future navigation
     * item is a cosmetic gap, not a broken one.
     */
    private const NAV_ICONS = [
        'Diary entry' => '📔',
        'Calendar' => '📅',
        'Summary' => '📊',
        'Milestones' => '🏆',
        'Viewers' => '👥',
    ];

    public static function render(array $navigation, ?string $signOutCsrfToken = null): string
    {
        $safeBanner = htmlspecialchars(self::BANNER, ENT_QUOTES, 'UTF-8');

        $links = '';
        foreach ($navigation as $item) {
            $icon = self::NAV_ICONS[$item->label] ?? null;
            $safeIcon = $icon !== null ? '<span class="nav-grid__icon" aria-hidden="true">' . $icon . '</span> ' : '';
            $links .= '                    <li><a class="nav-grid__link" href="' . htmlspecialchars($item->path, ENT_QUOTES, 'UTF-8') . '">'
                . $safeIcon . htmlspecialchars($item->label, ENT_QUOTES, 'UTF-8') . '</a></li>' . "\n";
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
            . '    <header class="app-header">' . "\n"
            . '        <div class="app-header__bar">' . "\n"
            . '            <h1 class="app-header__title">' . $safeBanner . '</h1>' . "\n"
            . $signOutForm
            . '        </div>' . "\n"
            . '    </header>' . "\n"
            . '    <main id="main">' . "\n"
            . '        <div class="card">' . "\n"
            . '            <nav aria-label="Main">' . "\n"
            . '                <ul class="nav-grid">' . "\n"
            . $links
            . '                </ul>' . "\n"
            . '            </nav>' . "\n"
            . '        </div>' . "\n"
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

        return '            <form method="post" action="' . $safeAction . '" class="form-inline app-header__sign-out">' . "\n"
            . '                <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . '                <button type="submit" class="button button--secondary">'
            . '<span aria-hidden="true">🚪</span> ' . $safeLabel . '</button>' . "\n"
            . '            </form>' . "\n";
    }
}
