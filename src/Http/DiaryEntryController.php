<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Ai\AiFeedbackService;
use Diary\Ai\FeedbackOutcome;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryInputValidator;
use Diary\Diary\DiaryService;
use Diary\Diary\QuestionDefinition;
use Diary\Diary\QuestionSet;
use Diary\Diary\SubmittedAnswers;
use Diary\Auth\SecurityContext;
use Diary\Support\Clock;
use Diary\Support\LocalDate;
use Diary\Support\Operation;
use Diary\Support\OperationKind;

/**
 * The diary entry page (Requirements 5.1, 5.3, 5.6, 8.2).
 *
 * This page's whole purpose is writing a Diary_Entry, so both its GET (present
 * the form) and its POST (accept a submission) are authorised as a
 * `WriteDiaryEntry` operation through {@see AccessControlService}, the single
 * enforcement point - not just the POST. The pipeline's
 * {@see AuthorisationMiddleware} already classifies a state-changing request
 * this way by default, so the POST is refused before this controller runs for
 * a viewer or anonymous context; the defensive check here is what also keeps a
 * viewer from landing on the create form itself (Requirement 5.6), since the
 * default resolver would otherwise treat a plain GET as an ordinary diary-data
 * read and let a viewer see it.
 *
 * A rejected submission is redisplayed on this same response - not a
 * redirect - with the submitted answers preserved exactly and the field
 * messages from {@see DiaryInputValidator} shown beside their fields
 * (Requirement 5.5). A fresh GET never prefills from a stored entry: the
 * question set starts blank except for the date, which defaults to today.
 *
 * A successful submission calls {@see AiFeedbackService::generateForEntry()}
 * after the entry is already committed, and redisplays the saved entry with
 * the outcome rendered through the shared {@see FeedbackView} partial: the
 * recommendation on success, or the "feedback is temporarily unavailable"
 * notice with a retry control on failure (Requirements 6.5, 6.6). The retry
 * control posts to {@see RETRY_FEEDBACK_PATH}, handled by
 * {@see retryFeedback()}, which re-fetches the entry by date and re-invokes
 * the provider - it never re-runs {@see DiaryInputValidator} or touches
 * `diary_entries` at all.
 */
final class DiaryEntryController
{
    public const HEADING = 'Diary entry';

    /** Requirement 5.3 wording for a successful submission. */
    public const SAVED_MESSAGE = 'Your diary entry has been saved.';

    /** Where the feedback retry control (Requirement 6.5) posts back to. */
    public const RETRY_FEEDBACK_PATH = '/diary/feedback/retry';

    /** The hidden field the retry form carries: which entry's date to retry. */
    public const RETRY_DATE_FIELD = 'entry_date';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly DiaryService $diaryService,
        private readonly DiaryInputValidator $validator,
        private readonly CsrfGuard $csrf,
        private readonly Clock $clock,
        private readonly AiFeedbackService $aiFeedbackService,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authoriseOwnerOperation($context, self::viewOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $answers = SubmittedAnswers::blank()
            ->with(QuestionSet::DATE_FIELD, LocalDate::today($this->clock)->toIso());

        return Response::html(self::render(
            $this->diaryService->questionSet(),
            $answers,
            null,
            [],
            $this->csrf->issueFor($request),
            null,
        ));
    }

    public function submit(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authoriseOwnerOperation($context, self::submitOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $answers = SubmittedAnswers::fromForm($request->formParams());
        $validation = $this->validator->validate($answers);

        if ($validation->isRejected()) {
            return Response::html(self::render(
                $this->diaryService->questionSet(),
                $validation->answers(),
                $validation->message(),
                $validation->fieldMessages(),
                $this->csrf->issueFor($request),
                null,
            ));
        }

        $owner = $this->access->resolveDataOwner($context);
        // Always accepted here: a rejected validation returned above.
        $entry = $this->diaryService->submitEntry($owner, $validation, $this->clock)->value();

        // Runs after the entry is already committed and outside that write's
        // transaction (Requirement 6.5): nothing this call does can roll the
        // entry back, and the entry is redisplayed whatever it returns.
        $outcome = $this->aiFeedbackService->generateForEntry($entry, $this->clock);

        return Response::html(self::render(
            $this->diaryService->questionSet(),
            $entry->input()->toSubmittedAnswers(),
            null,
            [],
            $this->csrf->issueFor($request),
            $entry,
            $outcome,
        ));
    }

    /**
     * The feedback retry control's target (Requirement 6.5): re-fetches the
     * entry for the given date and re-invokes the provider, updating the same
     * `cbt_recommendations` row rather than touching `diary_entries` at all.
     *
     * Authorised as the same {@see OperationKind::WriteDiaryEntry} operation
     * as the rest of this controller, so a viewer context is refused exactly
     * as a diary entry submission would be, and an anonymous request is sent
     * to the login page.
     */
    public function retryFeedback(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authoriseOwnerOperation($context, self::retryOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $owner = $this->access->resolveDataOwner($context);
        $date = LocalDate::tryFromString($request->formParam(self::RETRY_DATE_FIELD) ?? '');

        $entry = $date !== null ? $this->diaryService->entryForDate($owner, $date) : null;

        if ($entry === null) {
            return StatusPage::response(404, Router::NOT_FOUND_HEADING, Router::NOT_FOUND_MESSAGE);
        }

        $outcome = $this->aiFeedbackService->retry($entry, $this->clock);

        return Response::html(self::render(
            $this->diaryService->questionSet(),
            $entry->input()->toSubmittedAnswers(),
            null,
            [],
            $this->csrf->issueFor($request),
            $entry,
            $outcome,
        ));
    }

    /**
     * @param list<QuestionDefinition> $questions
     * @param array<string, string>    $fieldMessages
     */
    public static function render(
        array $questions,
        SubmittedAnswers $answers,
        ?string $summaryMessage,
        array $fieldMessages,
        string $csrfToken,
        ?DiaryEntry $savedEntry,
        ?FeedbackOutcome $feedbackOutcome = null,
    ): string {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');

        $questionFields = '';
        foreach ($questions as $question) {
            $questionFields .= self::renderQuestionField($question, $answers, $fieldMessages);
        }

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
            . '        <p><a href="/">Home</a></p>' . "\n"
            . '        <h1>' . $safeHeading . '</h1>' . "\n"
            . ($savedEntry !== null ? self::renderConfirmation($savedEntry, $feedbackOutcome, $csrfToken) : '')
            . self::renderErrorSummary($summaryMessage, $fieldMessages)
            . '        <form method="post" action="' . AccessControlService::DIARY_ENTRY_PATH . '">' . "\n"
            . '            <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . self::renderDateField($answers, $fieldMessages)
            . $questionFields
            . '            <button type="submit">Save entry</button>' . "\n"
            . '        </form>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /**
     * A denial for a non-owner context, rendered the same way
     * {@see AuthorisationMiddleware} would; null when the operation is
     * allowed and the caller should proceed.
     */
    private function authoriseOwnerOperation(SecurityContext $context, Operation $operation): ?Response
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
        return Operation::of(OperationKind::WriteDiaryEntry, 'diary_entry.view_form', $request->pathWithQuery());
    }

    private static function submitOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::WriteDiaryEntry, 'diary_entry.submit', $request->pathWithQuery());
    }

    private static function retryOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::WriteDiaryEntry, 'diary_entry.retry_feedback', $request->pathWithQuery());
    }

    private static function renderConfirmation(DiaryEntry $entry, ?FeedbackOutcome $feedbackOutcome, string $csrfToken): string
    {
        $safeMessage = htmlspecialchars(self::SAVED_MESSAGE, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($entry->date()->toIso(), ENT_QUOTES, 'UTF-8');

        $feedback = $feedbackOutcome !== null
            ? FeedbackView::render(
                $feedbackOutcome,
                self::RETRY_FEEDBACK_PATH,
                [self::RETRY_DATE_FIELD => $entry->date()->toIso()],
                CsrfGuard::FIELD_NAME,
                $csrfToken,
            )
            : '';

        return '        <div role="status">' . "\n"
            . '            <p>' . $safeMessage . ' (' . $safeDate . ')</p>' . "\n"
            . '        </div>' . "\n"
            . $feedback;
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
    private static function renderDateField(SubmittedAnswers $answers, array $fieldMessages): string
    {
        $field = QuestionSet::DATE_FIELD;
        $safeValue = htmlspecialchars($answers->value($field), ENT_QUOTES, 'UTF-8');
        $error = self::renderFieldError($field, $fieldMessages);
        $describedBy = $error === '' ? '' : ' aria-describedby="' . $field . '-error"';

        return '            <div>' . "\n"
            . '                <label for="' . $field . '">Date</label>' . "\n"
            . '                <input type="date" id="' . $field . '" name="' . $field . '" value="' . $safeValue . '" required' . $describedBy . '>' . "\n"
            . $error
            . '            </div>' . "\n";
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderQuestionField(
        QuestionDefinition $question,
        SubmittedAnswers $answers,
        array $fieldMessages,
    ): string {
        return $question->isScale()
            ? self::renderScaleField($question, $answers, $fieldMessages)
            : self::renderFreeTextField($question, $answers, $fieldMessages);
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderScaleField(
        QuestionDefinition $question,
        SubmittedAnswers $answers,
        array $fieldMessages,
    ): string {
        $field = $question->field();
        $safeField = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($question->label(), ENT_QUOTES, 'UTF-8');
        $currentValue = $answers->value($field);
        $error = self::renderFieldError($field, $fieldMessages);
        $describedBy = $error === '' ? '' : ' aria-describedby="' . $safeField . '-error"';
        $requiredAttr = $question->isRequired() ? ' required' : '';

        $placeholderLabel = $question->isRequired() ? 'Select a rating' : 'Prefer not to say';
        $options = '                <option value=""' . ($currentValue === '' ? ' selected' : '') . '>'
            . htmlspecialchars($placeholderLabel, ENT_QUOTES, 'UTF-8') . '</option>' . "\n";

        foreach ($question->scalePoints() as $point) {
            $pointValue = (string) $point;
            $selected = $currentValue === $pointValue ? ' selected' : '';
            $options .= '                <option value="' . $pointValue . '"' . $selected . '>'
                . htmlspecialchars($pointValue . ' - ' . $question->labelForPoint($point), ENT_QUOTES, 'UTF-8')
                . '</option>' . "\n";
        }

        return '            <div>' . "\n"
            . '                <label for="' . $safeField . '">' . $safeLabel . '</label>' . "\n"
            . '                <select id="' . $safeField . '" name="' . $safeField . '"' . $requiredAttr . $describedBy . '>' . "\n"
            . $options
            . '                </select>' . "\n"
            . $error
            . '            </div>' . "\n";
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderFreeTextField(
        QuestionDefinition $question,
        SubmittedAnswers $answers,
        array $fieldMessages,
    ): string {
        $field = $question->field();
        $safeField = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($question->label(), ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars($answers->value($field), ENT_QUOTES, 'UTF-8');
        $error = self::renderFieldError($field, $fieldMessages);
        $describedBy = $error === '' ? '' : ' aria-describedby="' . $safeField . '-error"';

        return '            <div>' . "\n"
            . '                <label for="' . $safeField . '">' . $safeLabel . '</label>' . "\n"
            . '                <textarea id="' . $safeField . '" name="' . $safeField . '"' . $describedBy . '>' . $safeValue . '</textarea>' . "\n"
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
