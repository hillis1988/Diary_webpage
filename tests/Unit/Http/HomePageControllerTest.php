<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Access\NavigationItem;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Http\CsrfGuard;
use Diary\Http\HomePageController;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 3.1: the banner text is fixed. Requirement 3.2: the page renders
 * exactly the navigation {@see AccessControlService::navigationFor()} returns,
 * with no second decision about visibility made in the view.
 */
final class HomePageControllerTest extends TestCase
{
    public function testBannerTextIsExact(): void
    {
        self::assertSame('Roy Hillis personal diary', HomePageController::BANNER);
    }

    public function testRenderEscapesTheBannerAndEveryLink(): void
    {
        $html = HomePageController::render([
            new NavigationItem('<script>x</script>', '/diary'),
        ]);

        self::assertStringContainsString('Roy Hillis personal diary', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('href="/diary"', $html);
    }

    public function testRenderWithNoNavigationStillRendersTheBanner(): void
    {
        $html = HomePageController::render([]);

        self::assertStringContainsString('Roy Hillis personal diary', $html);
        self::assertStringContainsString('<nav', $html);
    }

    public function testShowRendersOwnerNavigationFromTheResolvedContext(): void
    {
        $clock = FixedClock::at('2024-03-01 09:00:00');
        $access = new AccessControlService($clock);
        $controller = new HomePageController($access, new CsrfGuard(str_repeat('k', 32), $clock));

        $userId = UserId::fromString(Ulid::generate($clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('b', 64)),
            userId: $userId,
            contextRole: UserRole::Owner,
            dataOwnerId: $userId,
            createdAt: $clock->now(),
            lastActivityAt: $clock->now(),
        );

        $request = Request::of('GET', '/')
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, SecurityContext::forSession($session));

        $response = $controller->show($request);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Diary entry', $response->body());
        self::assertStringContainsString('Milestones', $response->body());
    }

    public function testShowRendersASignOutFormForAnAuthenticatedContext(): void
    {
        $clock = FixedClock::at('2024-03-01 09:00:00');
        $access = new AccessControlService($clock);
        $controller = new HomePageController($access, new CsrfGuard(str_repeat('k', 32), $clock));

        $userId = UserId::fromString(Ulid::generate($clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('b', 64)),
            userId: $userId,
            contextRole: UserRole::Viewer,
            dataOwnerId: $userId,
            createdAt: $clock->now(),
            lastActivityAt: $clock->now(),
        );

        $request = Request::of('GET', '/')
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, SecurityContext::forSession($session));

        $response = $controller->show($request);

        self::assertStringContainsString('action="' . AccessControlService::LOGOUT_PATH . '"', $response->body());
        self::assertStringContainsString(HomePageController::SIGN_OUT_LABEL, $response->body());
    }

    public function testShowWithNoResolvedContextRendersNoNavigationOrSignOutForm(): void
    {
        $clock = FixedClock::at('2024-03-01 09:00:00');
        $controller = new HomePageController(new AccessControlService($clock), new CsrfGuard(str_repeat('k', 32), $clock));

        $response = $controller->show(Request::of('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('Diary entry', $response->body());
        self::assertStringNotContainsString('<form', $response->body());
    }
}
