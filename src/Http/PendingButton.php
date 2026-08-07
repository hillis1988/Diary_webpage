<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * A submit button for a form whose response waits on the AI provider.
 *
 * Every one of those submissions is a full page load that cannot start
 * painting until an HTTPS call to the model has come back, which takes several
 * seconds. Without a signal in the page the press was made in, a click reads
 * as ignored and gets repeated. So the button says what it is doing:
 * `public/assets/app.js` swaps its label for {@see PENDING_LABEL_ATTRIBUTE}'s
 * value, puts a spinner in front of it, and refuses a second submission while
 * the first is in flight.
 *
 * The waiting label travels in a data attribute rather than a second element
 * because the waiting state does not exist without JavaScript: the
 * Content-Security-Policy forbids inline script, and with scripting off this
 * has to stay an ordinary submit button on a page that still works. Callers
 * must therefore link `/assets/app.js`; the button degrades silently to its
 * resting label if they do not.
 */
final class PendingButton
{
    /**
     * The attribute app.js keys on: its presence marks a button as AI-backed,
     * its value is the label shown while the request is in flight.
     */
    public const PENDING_LABEL_ATTRIBUTE = 'data-pending-label';

    private function __construct()
    {
    }

    /**
     * @param string $labelHtml    the resting label, already escaped by the caller
     *                             (these labels carry entities such as `&rsquo;`)
     * @param string $pendingLabel the waiting label, as plain text; escaped here
     * @param string $classes      the button's class attribute
     */
    public static function render(string $labelHtml, string $pendingLabel, string $classes = 'button'): string
    {
        $safeClasses = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');
        $safePendingLabel = htmlspecialchars($pendingLabel, ENT_QUOTES, 'UTF-8');

        return '<button type="submit" class="' . $safeClasses . '" '
            . self::PENDING_LABEL_ATTRIBUTE . '="' . $safePendingLabel . '">'
            . $labelHtml
            . '</button>';
    }
}
