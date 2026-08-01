<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
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
 * There is no AI_Feedback_Service yet (a later task), so a successful
 * submission redisplays the saved entry with a pending notice in place of a
 * recommendation. That notice is the only extension point this class assumes:
 * task 10 replaces it with the rendered CBT_Recommendation or its own failure
 * message.
 */
final class DiaryEntryController
{
    public const HEADING = 'Diary entry';

    /** Requirement 5.3 wording for a successful submission. */
    public const SAVED_MESSAGE = 'Your diary entry has been saved.';

    /** Placeholder shown until AI_Feedback_Service (task 10) exists. */
    public const RECOMMENDATION_PENDING_MESSAGE = 'CBT-style feedback for this entry is not available yet.';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly DiaryService $diaryService,
        private readonly DiaryInputValidator $validator,
        private readonly CsrfGuard $csrf,
        private readonly Clock $clock,
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

        return Response::html(self::render(
            $this->diaryService->questionSet(),
            $entry->input()->toSubmittedAnswers(),
            null,
            [],
            $this->csrf->issueFor($request),
            $entry,
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
            . ($savedEntry !== null ? self::renderConfirmation($savedEntry) : '')
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

    private static function renderConfirmation(DiaryEntry $entry): string
    {
        $safeMessage = htmlspecialchars(self::SAVED_MESSAGE, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($entry->date()->toIso(), ENT_QUOTES, 'UTF-8');
        $safePending = htmlspecialchars(self::RECOMMENDATION_PENDING_MESSAGE, ENT_QUOTES, 'UTF-8');

        return '        <div role="status">' . "\n"
            . '            <p>' . $safeMessage . ' (' . $safeDate . ')</p>' . "\n"
            . '            <p>' . $safePending . '</p>' . "\n"
            . '        </div>' . "\n";
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
