<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Access;

use Diary\Access\AccessControlService;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * navigationFor() must return exactly the controls the permission matrix would
 * allow for the session's context role (Requirement 3.2), omitting diary-entry
 * and milestone creation in a viewer context (Requirement 3.3).
 */
final class NavigationTest extends TestCase
{
    private FixedClock $clock;
    private AccessControlService $access;

    protected function setUp(): void
    {
        $this->clock = FixedClock::at('2024-03-01 09:00:00');
        $this->access = new AccessControlService($this->clock);
    }

    public function testAnonymousContextGetsNoNavigation(): void
    {
        self::assertSame([], $this->access->navigationFor(SecurityContext::anonymous()));
    }

    public function testOwnerContextGetsEveryControl(): void
    {
        $items = $this->access->navigationFor($this->contextFor(UserRole::Owner));

        self::assertSame(
            ['Diary entry', 'Calendar', 'Summary', 'Milestones', 'Viewers'],
            array_map(static fn ($item) => $item->label, $items)
        );
    }

    public function testViewerContextOmitsCreationControlsButKeepsReads(): void
    {
        $items = $this->access->navigationFor($this->contextFor(UserRole::Viewer));

        self::assertSame(
            ['Calendar', 'Summary'],
            array_map(static fn ($item) => $item->label, $items)
        );
    }

    public function testEveryReturnedItemMatchesWhatAuthoriseWouldAllow(): void
    {
        foreach ([UserRole::Owner, UserRole::Viewer] as $role) {
            $context = $this->contextFor($role);

            foreach ($this->access->navigationFor($context) as $item) {
                self::assertNotSame('', $item->path);
                self::assertStringStartsWith('/', $item->path);
            }
        }
    }

    private function contextFor(UserRole $role): SecurityContext
    {
        $userId = UserId::fromString(Ulid::generate($this->clock));

        $session = new Session(
            id: SessionId::fromString(str_repeat('a', 64)),
            userId: $userId,
            contextRole: $role,
            dataOwnerId: $userId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }
}
