<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * The minimal HTML the pipeline itself needs before any template exists: a rejected
 * form, an unknown path, a method that does not apply.
 *
 * Deliberately tiny and self-contained. These pages are produced by middleware, so
 * they must not depend on a session, a database, or a layout that might itself fail;
 * and they carry a plain user-facing sentence only, never a stack trace, SQL or a
 * configuration value. Everything interpolated is escaped.
 */
final class StatusPage
{
    private function __construct()
    {
    }

    public static function response(int $status, string $heading, string $message): Response
    {
        return Response::html(self::render($heading, $message), $status);
    }

    public static function render(string $heading, string $message): string
    {
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

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
            . '        <div class="card">' . "\n"
            . '            <h1>' . $safeHeading . '</h1>' . "\n"
            . '            <p>' . $safeMessage . '</p>' . "\n"
            . '        </div>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }
}
