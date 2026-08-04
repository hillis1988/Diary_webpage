<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Ai\CbtRecommendation;
use Diary\Ai\FeedbackOutcome;

/**
 * The shared partial every AI surface renders a {@see FeedbackOutcome}
 * through (Requirements 6.5, 6.6, 9.6).
 *
 * Being one class used by both the diary entry page (task 10.6) and the
 * summary page (task 14.6) is the point: the medical disclaimer cannot be
 * rendered on one AI surface and forgotten on another, because there is only
 * one place that renders it. The disclaimer is written on *every* outcome
 * this partial is asked to render - success and failure alike - rather than
 * only on a generated recommendation. Requirement 6.6 only names the success
 * case, but design.md is explicit that the summary page's disclaimer "renders
 * whenever the service is invoked, regardless of outcome"; since this is one
 * shared partial, both surfaces get that same, more cautious behaviour rather
 * than two different rules for when the notice appears.
 *
 * The retry control is optional, not automatic: passing null for
 * `$retryAction` renders the unavailable notice with no form beneath it. That
 * is what lets a future caller (the summary page) reuse this partial without
 * inheriting a control it has no retry route to point at.
 */
final class FeedbackView
{
    /** Requirement 6.6's wording, rendered on every outcome. */
    public const DISCLAIMER_MESSAGE = 'This recommendation is automated guidance and is not a substitute for professional medical advice.';

    /** Label on the retry control shown after a failed outcome. */
    public const RETRY_BUTTON_LABEL = 'Try again';

    /** Heading shown above every generated recommendation, here and on the calendar page. */
    public const NOTES_HEADING = "AI CBT Therapist's notes";

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $retryHiddenFields extra hidden fields the retry form must
     *                                                  carry (e.g. which entry to retry for);
     *                                                  ignored when $retryAction is null
     */
    public static function render(
        FeedbackOutcome $outcome,
        ?string $retryAction = null,
        array $retryHiddenFields = [],
        string $csrfFieldName = '',
        string $csrfToken = '',
    ): string {
        $body = $outcome->isGenerated()
            ? self::renderRecommendation($outcome)
            : self::renderUnavailable($outcome, $retryAction, $retryHiddenFields, $csrfFieldName, $csrfToken);

        return '        <div class="ai-feedback card card--spotlight">' . "\n"
            . $body
            . self::renderDisclaimer()
            . '        </div>' . "\n";
    }

    /**
     * The disclaimer paragraph alone, with no surrounding outcome-specific
     * body (Requirements 6.6, 9.6). Extracted so the summary page
     * (task 14.6) can render the same fixed wording around its own
     * {@see \Diary\Ai\SummaryOutcome}-shaped body, without forcing
     * `SummaryOutcome` and `FeedbackOutcome` - different domains, CBT
     * recommendation versus progress narrative plus metrics - to share a
     * type just to share this one paragraph.
     */
    public static function renderDisclaimer(): string
    {
        $safeDisclaimer = htmlspecialchars(self::DISCLAIMER_MESSAGE, ENT_QUOTES, 'UTF-8');

        return '            <p class="disclaimer">' . $safeDisclaimer . '</p>' . "\n";
    }

    private static function renderRecommendation(FeedbackOutcome $outcome): string
    {
        return self::renderNotes($outcome->recommendation());
    }

    /**
     * The generated recommendation rendered as a clearly separated, headed
     * section: an "AI CBT Therapist's notes" heading, a divider setting it
     * apart from whatever precedes it (the saved-entry notice, the diary
     * entry itself on the calendar page), and each of the two recommendation
     * fields under its own sub-heading rather than as two bare paragraphs.
     * Shared by this partial and {@see \Diary\Http\CalendarController} so a
     * past entry's stored recommendation is presented identically to a
     * freshly generated one.
     */
    public static function renderNotes(CbtRecommendation $recommendation): string
    {
        $safeHeading = htmlspecialchars(self::NOTES_HEADING, ENT_QUOTES, 'UTF-8');
        $safeFocus = htmlspecialchars($recommendation->positiveFocus(), ENT_QUOTES, 'UTF-8');
        $safeChange = htmlspecialchars($recommendation->suggestedChange(), ENT_QUOTES, 'UTF-8');

        return '            <div class="cbt-notes">' . "\n"
            . '                <hr class="cbt-notes__divider">' . "\n"
            . '                <h3 class="cbt-notes__heading">' . $safeHeading . '</h3>' . "\n"
            . '                <div class="cbt-notes__section">' . "\n"
            . '                    <h4 class="cbt-notes__label">Positive focus</h4>' . "\n"
            . '                    <p>' . $safeFocus . '</p>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="cbt-notes__section">' . "\n"
            . '                    <h4 class="cbt-notes__label">Suggested change</h4>' . "\n"
            . '                    <p>' . $safeChange . '</p>' . "\n"
            . '                </div>' . "\n"
            . '            </div>' . "\n";
    }

    /**
     * @param array<string, string> $retryHiddenFields
     */
    private static function renderUnavailable(
        FeedbackOutcome $outcome,
        ?string $retryAction,
        array $retryHiddenFields,
        string $csrfFieldName,
        string $csrfToken,
    ): string {
        $safeReason = htmlspecialchars($outcome->reason() ?? FeedbackOutcome::UNAVAILABLE_MESSAGE, ENT_QUOTES, 'UTF-8');

        $html = '            <p class="notice">' . $safeReason . '</p>' . "\n";

        if ($retryAction !== null) {
            $html .= self::renderRetryForm($retryAction, $retryHiddenFields, $csrfFieldName, $csrfToken);
        }

        return $html;
    }

    /**
     * @param array<string, string> $hiddenFields
     */
    private static function renderRetryForm(string $action, array $hiddenFields, string $csrfFieldName, string $csrfToken): string
    {
        $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
        $safeCsrfField = htmlspecialchars($csrfFieldName, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars(self::RETRY_BUTTON_LABEL, ENT_QUOTES, 'UTF-8');

        $hidden = '';
        foreach ($hiddenFields as $name => $value) {
            $safeName = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
            $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $hidden .= '                <input type="hidden" name="' . $safeName . '" value="' . $safeValue . '">' . "\n";
        }

        return '            <form method="post" action="' . $safeAction . '">' . "\n"
            . '                <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . $hidden
            . '                <button type="submit" class="button">' . $safeLabel . '</button>' . "\n"
            . '            </form>' . "\n";
    }
}
