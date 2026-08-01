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
 */
final class HomePageController
{
    /** Requirement 3.1: the exact banner text. */
    public const BANNER = 'Roy Hillis personal diary';

    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function show(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        return Response::html(self::render($this->access->navigationFor($context)));
    }

    /**
     * @param list<NavigationItem> $navigation
     */
    public static function render(array $navigation): string
    {
        $safeBanner = htmlspecialchars(self::BANNER, ENT_QUOTES, 'UTF-8');

        $links = '';
        foreach ($navigation as $item) {
            $links .= '                <li><a href="' . htmlspecialchars($item->path, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($item->label, ENT_QUOTES, 'UTF-8') . '</a></li>' . "\n";
        }

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
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }
}
