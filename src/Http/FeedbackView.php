<?php

declare(strict_types=1);

namespace Diary\Http;

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

        $safeDisclaimer = htmlspecialchars(self::DISCLAIMER_MESSAGE, ENT_QUOTES, 'UTF-8');

        return '        <div class="ai-feedback">' . "\n"
            . $body
            . '            <p class="disclaimer">' . $safeDisclaimer . '</p>' . "\n"
            . '        </div>' . "\n";
    }

    private static function renderRecommendation(FeedbackOutcome $outcome): string
    {
        $recommendation = $outcome->recommendation();
        $safeFocus = htmlspecialchars($recommendation->positiveFocus(), ENT_QUOTES, 'UTF-8');
        $safeChange = htmlspecialchars($recommendation->suggestedChange(), ENT_QUOTES, 'UTF-8');

        return '            <p>' . $safeFocus . '</p>' . "\n"
            . '            <p>' . $safeChange . '</p>' . "\n";
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

        $html = '            <p>' . $safeReason . '</p>' . "\n";

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
            . '                <button type="submit">' . $safeLabel . '</button>' . "\n"
            . '            </form>' . "\n";
    }
}
