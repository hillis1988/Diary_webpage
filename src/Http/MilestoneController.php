<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Auth\SecurityContext;
use Diary\Milestone\Milestone;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneId;
use Diary\Milestone\MilestoneInputValidator;
use Diary\Milestone\MilestoneService;
use Diary\Milestone\MilestoneSubmission;
use Diary\Support\Clock;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Support\Operation;
use Diary\Support\OperationKind;
use InvalidArgumentException;

/**
 * The milestones pages (Requirements 10.1, 10.2, 10.3, 10.5).
 *
 * The list (GET on {@see AccessControlService::MILESTONES_PATH}) is a *read*:
 * it is authorised as {@see OperationKind::ReadDiaryData}, the same kind the
 * calendar and summary pages use, so a viewer context can open it just as
 * Requirement 7.2 requires - only the create, edit and delete controls are
 * withheld from the rendered page for that context (Requirement 3.3), which
 * {@see renderList()} decides purely from the `$isOwner` flag it is handed
 * rather than re-deriving it.
 *
 * Every other handler - the create form, the edit form and the delete action -
 * is authorised as {@see OperationKind::WriteMilestone} on both its GET and its
 * POST, following {@see DiaryEntryController}'s dual-authorisation pattern: the
 * defensive check here is what keeps a viewer from even loading the create or
 * edit form, not just from submitting it (Requirement 10.5).
 *
 * A rejected create or edit submission is redisplayed on the same response -
 * not a redirect - with the submitted values preserved exactly and the field
 * messages from {@see MilestoneInputValidator} shown beside their fields
 * (Requirement 10.4). A successful create, update or delete redirects back to
 * the list.
 */
final class MilestoneController
{
    public const HEADING = 'Milestones';
    public const CREATE_HEADING = 'Add milestone';
    public const EDIT_HEADING = 'Edit milestone';

    public const NEW_PATH = AccessControlService::MILESTONES_PATH . '/new';

    public const NO_MILESTONES_MESSAGE = 'No milestones recorded yet.';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly MilestoneService $milestoneService,
        private readonly MilestoneInputValidator $validator,
        private readonly CsrfGuard $csrf,
        private readonly Clock $clock,
    ) {
    }

    public function list(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::listOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $owner = $this->access->resolveDataOwner($context);
        $milestones = $this->milestoneService->inRange($owner, self::allTimeRange());

        return Response::html(self::renderList($milestones, $context->isOwner(), $this->csrf->issueFor($request)));
    }

    public function showCreateForm(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::viewCreateOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $submission = MilestoneSubmission::of([
            MilestoneSubmission::DATE_FIELD => LocalDate::today($this->clock)->toIso(),
        ]);

        return Response::html(self::renderForm(
            self::CREATE_HEADING,
            $submission,
            null,
            [],
            $this->csrf->issueFor($request),
            self::NEW_PATH,
        ));
    }

    public function submitCreate(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, Operation::createMilestone($request->pathWithQuery()));
        if ($denied !== null) {
            return $denied;
        }

        $submission = MilestoneSubmission::fromForm($request->formParams());
        $validation = $this->validator->validate($submission);

        if ($validation->isRejected()) {
            return Response::html(self::renderForm(
                self::CREATE_HEADING,
                $validation->submission(),
                $validation->message(),
                $validation->fieldMessages(),
                $this->csrf->issueFor($request),
                self::NEW_PATH,
            ));
        }

        $owner = $this->access->resolveDataOwner($context);
        // Always accepted here: a rejected validation returned above.
        $this->milestoneService->create($owner, $validation, $this->clock)->value();

        return Response::redirect(AccessControlService::MILESTONES_PATH, Decision::REDIRECT_STATUS);
    }

    /**
     * @param array<string, string> $params
     */
    public function showEditForm(Request $request, array $params): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::viewEditOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $id = self::parseId($params['id'] ?? '');
        if ($id === null) {
            return self::notFound();
        }

        $owner = $this->access->resolveDataOwner($context);
        $milestone = $this->milestoneService->find($owner, $id);

        if ($milestone === null) {
            return self::notFound();
        }

        return Response::html(self::renderForm(
            self::EDIT_HEADING,
            self::submissionFor($milestone),
            null,
            [],
            $this->csrf->issueFor($request),
            self::editPath($id),
        ));
    }

    /**
     * @param array<string, string> $params
     */
    public function submitEdit(Request $request, array $params): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, Operation::updateMilestone($request->pathWithQuery()));
        if ($denied !== null) {
            return $denied;
        }

        $id = self::parseId($params['id'] ?? '');
        if ($id === null) {
            return self::notFound();
        }

        $submission = MilestoneSubmission::fromForm($request->formParams());
        $validation = $this->validator->validate($submission);

        if ($validation->isRejected()) {
            return Response::html(self::renderForm(
                self::EDIT_HEADING,
                $validation->submission(),
                $validation->message(),
                $validation->fieldMessages(),
                $this->csrf->issueFor($request),
                self::editPath($id),
            ));
        }

        $owner = $this->access->resolveDataOwner($context);
        $result = $this->milestoneService->update($owner, $id, $validation, $this->clock);

        if ($result->isFailure()) {
            return self::notFound();
        }

        return Response::redirect(AccessControlService::MILESTONES_PATH, Decision::REDIRECT_STATUS);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, Operation::deleteMilestone($request->pathWithQuery()));
        if ($denied !== null) {
            return $denied;
        }

        $id = self::parseId($params['id'] ?? '');
        if ($id === null) {
            return self::notFound();
        }

        $owner = $this->access->resolveDataOwner($context);
        $result = $this->milestoneService->delete($owner, $id);

        if ($result->isFailure()) {
            return self::notFound();
        }

        return Response::redirect(AccessControlService::MILESTONES_PATH, Decision::REDIRECT_STATUS);
    }

    public static function editPath(MilestoneId $id): string
    {
        return AccessControlService::MILESTONES_PATH . '/' . $id->toString() . '/edit';
    }

    public static function deletePath(MilestoneId $id): string
    {
        return AccessControlService::MILESTONES_PATH . '/' . $id->toString() . '/delete';
    }

    /**
     * A wide-enough range to mean "every milestone the owner has recorded":
     * the list view has no date filter of its own (Requirement 10.1), and
     * {@see LocalDate::of()} bounds the calendar to years 1-9999 anyway, so
     * spanning that whole span is the "all-time" reading of
     * {@see MilestoneService::inRange()} without adding a second listing
     * method to the repository for one caller.
     */
    private static function allTimeRange(): DateRange
    {
        return DateRange::of(LocalDate::of(1, 1, 1), LocalDate::of(9999, 12, 31));
    }

    private static function submissionFor(Milestone $milestone): MilestoneSubmission
    {
        return MilestoneSubmission::of([
            MilestoneSubmission::DATE_FIELD => $milestone->date()->toIso(),
            MilestoneSubmission::DESCRIPTION_FIELD => $milestone->description(),
            MilestoneSubmission::CATEGORY_FIELD => $milestone->category()->value,
        ]);
    }

    private static function parseId(string $raw): ?MilestoneId
    {
        try {
            return MilestoneId::fromString($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function notFound(): Response
    {
        return StatusPage::response(404, Router::NOT_FOUND_HEADING, Router::NOT_FOUND_MESSAGE);
    }

    /**
     * A denial for a non-owner context, rendered the same way
     * {@see AuthorisationMiddleware} would; null when the operation is
     * allowed and the caller should proceed.
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

    private static function listOperation(Request $request): Operation
    {
        return Operation::readDiaryData('milestone.list', $request->pathWithQuery());
    }

    private static function viewCreateOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::WriteMilestone, 'milestone.view_create_form', $request->pathWithQuery());
    }

    private static function viewEditOperation(Request $request): Operation
    {
        return Operation::of(OperationKind::WriteMilestone, 'milestone.view_edit_form', $request->pathWithQuery());
    }

    /**
     * @param list<Milestone> $milestones
     */
    public static function renderList(array $milestones, bool $isOwner, string $csrfToken): string
    {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');

        $newLink = $isOwner
            ? '        <p><a href="' . htmlspecialchars(self::NEW_PATH, ENT_QUOTES, 'UTF-8') . '">Add milestone</a></p>' . "\n"
            : '';

        $body = $milestones === []
            ? '        <p>' . htmlspecialchars(self::NO_MILESTONES_MESSAGE, ENT_QUOTES, 'UTF-8') . '</p>' . "\n"
            : self::renderMilestoneList($milestones, $isOwner, $csrfToken);

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
            . $newLink
            . $body
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /**
     * @param list<Milestone> $milestones
     */
    private static function renderMilestoneList(array $milestones, bool $isOwner, string $csrfToken): string
    {
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

        $items = '';
        foreach ($milestones as $milestone) {
            $safeDate = htmlspecialchars($milestone->date()->toIso(), ENT_QUOTES, 'UTF-8');
            $safeDescription = htmlspecialchars($milestone->description(), ENT_QUOTES, 'UTF-8');
            $safeCategory = htmlspecialchars(ucfirst($milestone->category()->value), ENT_QUOTES, 'UTF-8');

            $controls = '';
            if ($isOwner) {
                $safeEditPath = htmlspecialchars(self::editPath($milestone->id()), ENT_QUOTES, 'UTF-8');
                $safeDeletePath = htmlspecialchars(self::deletePath($milestone->id()), ENT_QUOTES, 'UTF-8');

                $controls = ' <a href="' . $safeEditPath . '">Edit</a>'
                    . ' <form method="post" action="' . $safeDeletePath . '" style="display:inline">'
                    . '<input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">'
                    . '<button type="submit">Delete</button>'
                    . '</form>';
            }

            $items .= '                <li>' . $safeDate . ' - ' . $safeDescription . ' (' . $safeCategory . ')' . $controls . '</li>' . "\n";
        }

        return '        <ul>' . "\n" . $items . '        </ul>' . "\n";
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    public static function renderForm(
        string $heading,
        MilestoneSubmission $submission,
        ?string $summaryMessage,
        array $fieldMessages,
        string $csrfToken,
        string $actionPath,
    ): string {
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeCsrfField = htmlspecialchars(CsrfGuard::FIELD_NAME, ENT_QUOTES, 'UTF-8');
        $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
        $safeAction = htmlspecialchars($actionPath, ENT_QUOTES, 'UTF-8');

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
            . '        <p><a href="' . AccessControlService::MILESTONES_PATH . '">Milestones</a></p>' . "\n"
            . '        <h1>' . $safeHeading . '</h1>' . "\n"
            . self::renderErrorSummary($summaryMessage, $fieldMessages)
            . '        <form method="post" action="' . $safeAction . '">' . "\n"
            . '            <input type="hidden" name="' . $safeCsrfField . '" value="' . $safeCsrfToken . '">' . "\n"
            . self::renderDateField($submission, $fieldMessages)
            . self::renderDescriptionField($submission, $fieldMessages)
            . self::renderCategoryField($submission, $fieldMessages)
            . '            <button type="submit">Save milestone</button>' . "\n"
            . '        </form>' . "\n"
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
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
    private static function renderDateField(MilestoneSubmission $submission, array $fieldMessages): string
    {
        $field = MilestoneSubmission::DATE_FIELD;
        $safeValue = htmlspecialchars($submission->date(), ENT_QUOTES, 'UTF-8');
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
    private static function renderDescriptionField(MilestoneSubmission $submission, array $fieldMessages): string
    {
        $field = MilestoneSubmission::DESCRIPTION_FIELD;
        $safeValue = htmlspecialchars($submission->description(), ENT_QUOTES, 'UTF-8');
        $error = self::renderFieldError($field, $fieldMessages);
        $describedBy = $error === '' ? '' : ' aria-describedby="' . $field . '-error"';

        return '            <div>' . "\n"
            . '                <label for="' . $field . '">Description</label>' . "\n"
            . '                <textarea id="' . $field . '" name="' . $field . '"' . $describedBy . '>' . $safeValue . '</textarea>' . "\n"
            . $error
            . '            </div>' . "\n";
    }

    /**
     * @param array<string, string> $fieldMessages
     */
    private static function renderCategoryField(MilestoneSubmission $submission, array $fieldMessages): string
    {
        $field = MilestoneSubmission::CATEGORY_FIELD;
        $currentValue = $submission->category();
        $error = self::renderFieldError($field, $fieldMessages);
        $describedBy = $error === '' ? '' : ' aria-describedby="' . $field . '-error"';

        $options = '                <option value="">Choose a category</option>' . "\n";
        foreach (MilestoneCategory::values() as $value) {
            $selected = $currentValue === $value ? ' selected' : '';
            $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars(ucfirst($value), ENT_QUOTES, 'UTF-8');
            $options .= '                <option value="' . $safeValue . '"' . $selected . '>' . $safeLabel . '</option>' . "\n";
        }

        return '            <div>' . "\n"
            . '                <label for="' . $field . '">Category</label>' . "\n"
            . '                <select id="' . $field . '" name="' . $field . '" required' . $describedBy . '>' . "\n"
            . $options
            . '                </select>' . "\n"
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
