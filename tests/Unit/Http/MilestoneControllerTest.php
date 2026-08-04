<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Http\CsrfGuard;
use Diary\Http\MilestoneController;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneId;
use Diary\Milestone\MilestoneInputValidator;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Milestone\MilestoneSubmission;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The milestone pages and controller (Requirements 10.1, 10.2, 10.3, 10.5).
 *
 * Uses an in-memory SQLite schema standing in for migrations 003 and 006,
 * following the pattern in tests/Unit/Http/DiaryEntryControllerTest.php.
 */
final class MilestoneControllerTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private MilestoneController $controller;
    private MilestoneService $milestoneService;
    private CsrfGuard $csrf;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');

        $this->pdo->exec(
            'CREATE TABLE encryption_keys (
                id          CHAR(26)   NOT NULL PRIMARY KEY,
                wrapped_dek BLOB       NOT NULL,
                wrap_nonce  BLOB       NOT NULL,
                created_at  DATETIME   NOT NULL,
                retired_at  DATETIME   NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE milestones (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                owner_id           CHAR(26)   NOT NULL,
                milestone_date     DATE       NOT NULL,
                key_id             CHAR(26)   NOT NULL,
                nonce              BLOB       NOT NULL,
                payload_ciphertext BLOB       NOT NULL,
                created_at         DATETIME   NOT NULL,
                updated_at         DATETIME   NOT NULL
            )'
        );

        $this->clock = FixedClock::at('2025-03-15 09:00:00');

        $keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $codec = new PayloadCodec(new Crypto($keyRing));
        $repository = new MilestoneRepository($this->pdo, $codec);
        $this->milestoneService = new MilestoneService($repository);

        $this->access = new AccessControlService($this->clock);
        $this->csrf = CsrfGuard::withMasterKey(str_repeat("\x2b", 32), $this->clock);

        $this->controller = new MilestoneController(
            $this->access,
            $this->milestoneService,
            new MilestoneInputValidator(),
            $this->csrf,
            $this->clock,
        );
    }

    private function ownerContext(): SecurityContext
    {
        $userId = UserId::fromString(Ulid::generate($this->clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('a', 64)),
            userId: $userId,
            contextRole: UserRole::Owner,
            dataOwnerId: $userId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    private function viewerContextFor(SecurityContext $ownerContext): SecurityContext
    {
        $viewerId = UserId::fromString(Ulid::generate($this->clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('c', 64)),
            userId: $viewerId,
            contextRole: UserRole::Viewer,
            dataOwnerId: $ownerContext->dataOwnerId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    private function getRequest(SecurityContext $context, string $path = AccessControlService::MILESTONES_PATH): Request
    {
        return Request::of('GET', $path)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    private function postRequest(SecurityContext $context, string $path, array $form): Request
    {
        return Request::of('POST', $path, form: $form)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    private function createMilestone(
        \Diary\Access\OwnerId $owner,
        string $date = '2025-03-10',
        string $description = 'Started a new medication',
        MilestoneCategory $category = MilestoneCategory::Medication,
    ): MilestoneId {
        $milestone = $this->milestoneService->create(
            $owner,
            \Diary\Milestone\MilestoneValidation::accepted(
                \Diary\Milestone\MilestoneInput::of(
                    \Diary\Support\LocalDate::fromString($date),
                    $description,
                    $category,
                ),
                MilestoneSubmission::blank(),
            ),
            $this->clock,
        )->value();

        return $milestone->id();
    }

    // --- List view ---

    public function testListInAnOwnerContextShowsEditAndDeleteControls(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $this->createMilestone($owner);

        $response = $this->controller->list($this->getRequest($context));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString('Started a new medication', $html);
        self::assertStringContainsString('Edit</a>', $html);
        self::assertStringContainsString('>Delete<', $html);
        self::assertStringContainsString(MilestoneController::NEW_PATH, $html);
    }

    public function testListInAViewerContextIsReadOnlyWithNoCreateEditOrDeleteControls(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $this->createMilestone($owner);

        $viewerContext = $this->viewerContextFor($ownerContext);

        $response = $this->controller->list($this->getRequest($viewerContext));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString('Started a new medication', $html);
        self::assertStringNotContainsString('Edit</a>', $html);
        self::assertStringNotContainsString('>Delete<', $html);
        self::assertStringNotContainsString(MilestoneController::NEW_PATH, $html);
    }

    public function testListAnonymousRedirectsToLogin(): void
    {
        $response = $this->controller->list(Request::of('GET', AccessControlService::MILESTONES_PATH));

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('Location'));
    }

    public function testListWithNoMilestonesShowsTheEmptyMessage(): void
    {
        $response = $this->controller->list($this->getRequest($this->ownerContext()));

        self::assertSame(200, $response->status());
        self::assertStringContainsString(MilestoneController::NO_MILESTONES_MESSAGE, $response->body());
    }

    // --- Create ---

    public function testShowCreateFormRendersTheCategorySelectorWithEveryCategory(): void
    {
        $response = $this->controller->showCreateForm($this->getRequest($this->ownerContext(), MilestoneController::NEW_PATH));

        self::assertSame(200, $response->status());
        $html = $response->body();
        foreach (MilestoneCategory::values() as $value) {
            self::assertStringContainsString('value="' . $value . '"', $html);
        }
    }

    public function testShowCreateFormInAViewerContextIsDenied(): void
    {
        $response = $this->controller->showCreateForm($this->getRequest($this->viewerContextFor($this->ownerContext()), MilestoneController::NEW_PATH));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
    }

    public function testSubmitCreateWithAValidMilestoneSavesItAndRedirectsToTheList(): void
    {
        $context = $this->ownerContext();
        $token = $this->csrf->issueFor($this->getRequest($context, MilestoneController::NEW_PATH));

        $request = $this->postRequest($context, MilestoneController::NEW_PATH, [
            CsrfGuard::FIELD_NAME => $token,
            MilestoneSubmission::DATE_FIELD => '2025-03-10',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Started a new medication',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Medication->value,
        ]);

        $response = $this->controller->submitCreate($request);

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::MILESTONES_PATH, $response->header('Location'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM milestones')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function testSubmitCreateWithABlankDescriptionRedisplaysTheSubmissionAndTheFieldMessage(): void
    {
        $context = $this->ownerContext();
        $token = $this->csrf->issueFor($this->getRequest($context, MilestoneController::NEW_PATH));

        $request = $this->postRequest($context, MilestoneController::NEW_PATH, [
            CsrfGuard::FIELD_NAME => $token,
            MilestoneSubmission::DATE_FIELD => '2025-03-10',
            MilestoneSubmission::DESCRIPTION_FIELD => '',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Medication->value,
        ]);

        $response = $this->controller->submitCreate($request);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(MilestoneInputValidator::DESCRIPTION_MESSAGE, $html);
        self::assertStringContainsString('value="2025-03-10"', $html);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM milestones')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function testSubmitCreateInAViewerContextIsDeniedAndWritesNothing(): void
    {
        $context = $this->viewerContextFor($this->ownerContext());

        $request = $this->postRequest($context, MilestoneController::NEW_PATH, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($context, MilestoneController::NEW_PATH)),
            MilestoneSubmission::DATE_FIELD => '2025-03-10',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Started a new medication',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Medication->value,
        ]);

        $response = $this->controller->submitCreate($request);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM milestones')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    // --- Edit ---

    public function testShowEditFormPrefillsTheExistingMilestonesValues(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $id = $this->createMilestone($owner, description: 'Started physiotherapy', category: MilestoneCategory::Lifestyle);

        $path = MilestoneController::editPath($id);
        $response = $this->controller->showEditForm($this->getRequest($context, $path), ['id' => $id->toString()]);

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString('Started physiotherapy', $html);
        self::assertStringContainsString('value="2025-03-10"', $html);
        self::assertStringContainsString('value="' . MilestoneCategory::Lifestyle->value . '" selected', $html);
    }

    public function testShowEditFormInAViewerContextIsDenied(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $id = $this->createMilestone($owner);

        $viewerContext = $this->viewerContextFor($ownerContext);
        $path = MilestoneController::editPath($id);

        $response = $this->controller->showEditForm($this->getRequest($viewerContext, $path), ['id' => $id->toString()]);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
    }

    public function testSubmitEditWithAValidChangeUpdatesTheMilestoneAndRedirectsToTheList(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $id = $this->createMilestone($owner);

        $path = MilestoneController::editPath($id);
        $token = $this->csrf->issueFor($this->getRequest($context, $path));

        $request = $this->postRequest($context, $path, [
            CsrfGuard::FIELD_NAME => $token,
            MilestoneSubmission::DATE_FIELD => '2025-03-12',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Started physiotherapy',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Lifestyle->value,
        ]);

        $response = $this->controller->submitEdit($request, ['id' => $id->toString()]);

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::MILESTONES_PATH, $response->header('Location'));

        $found = $this->milestoneService->find($owner, $id);
        self::assertNotNull($found);
        self::assertSame('Started physiotherapy', $found->description());
        self::assertSame(MilestoneCategory::Lifestyle, $found->category());
    }

    public function testSubmitEditForAMilestoneBelongingToAnotherOwnerReturnsNotFound(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $id = $this->createMilestone($owner);

        $otherOwnerContext = $this->ownerContext();
        $path = MilestoneController::editPath($id);
        $token = $this->csrf->issueFor($this->getRequest($otherOwnerContext, $path));

        $request = $this->postRequest($otherOwnerContext, $path, [
            CsrfGuard::FIELD_NAME => $token,
            MilestoneSubmission::DATE_FIELD => '2025-03-12',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Hijacked',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Other->value,
        ]);

        $response = $this->controller->submitEdit($request, ['id' => $id->toString()]);

        self::assertSame(404, $response->status());

        $found = $this->milestoneService->find($owner, $id);
        self::assertNotNull($found);
        self::assertSame('Started a new medication', $found->description());
    }

    public function testSubmitEditInAViewerContextIsDeniedAndWritesNothing(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $id = $this->createMilestone($owner);

        $viewerContext = $this->viewerContextFor($ownerContext);
        $path = MilestoneController::editPath($id);

        $request = $this->postRequest($viewerContext, $path, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($viewerContext, $path)),
            MilestoneSubmission::DATE_FIELD => '2025-03-12',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Hijacked',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Other->value,
        ]);

        $response = $this->controller->submitEdit($request, ['id' => $id->toString()]);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());

        $found = $this->milestoneService->find($owner, $id);
        self::assertNotNull($found);
        self::assertSame('Started a new medication', $found->description());
    }

    // --- Delete ---

    public function testDeleteRemovesTheMilestoneAndRedirectsToTheList(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $id = $this->createMilestone($owner);

        $path = MilestoneController::deletePath($id);
        $token = $this->csrf->issueFor($this->getRequest($context, $path));

        $request = $this->postRequest($context, $path, [
            CsrfGuard::FIELD_NAME => $token,
        ]);

        $response = $this->controller->delete($request, ['id' => $id->toString()]);

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::MILESTONES_PATH, $response->header('Location'));
        self::assertNull($this->milestoneService->find($owner, $id));
    }

    public function testDeleteInAViewerContextIsDeniedAndKeepsTheMilestone(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $id = $this->createMilestone($owner);

        $viewerContext = $this->viewerContextFor($ownerContext);
        $path = MilestoneController::deletePath($id);

        $request = $this->postRequest($viewerContext, $path, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($viewerContext, $path)),
        ]);

        $response = $this->controller->delete($request, ['id' => $id->toString()]);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
        self::assertNotNull($this->milestoneService->find($owner, $id));
    }

    public function testDeleteForAMilestoneBelongingToAnotherOwnerReturnsNotFound(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $id = $this->createMilestone($owner);

        $otherOwnerContext = $this->ownerContext();
        $path = MilestoneController::deletePath($id);

        $request = $this->postRequest($otherOwnerContext, $path, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($otherOwnerContext, $path)),
        ]);

        $response = $this->controller->delete($request, ['id' => $id->toString()]);

        self::assertSame(404, $response->status());
        self::assertNotNull($this->milestoneService->find($owner, $id));
    }
}
