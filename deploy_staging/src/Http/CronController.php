<?php

declare(strict_types=1);

namespace Diary\Http;

use DateInterval;
use Diary\Auth\SessionRepository;
use Diary\Storage\KeyRotationService;
use Diary\Storage\PurgeService;
use Diary\Support\Clock;

/**
 * The three cron endpoints IONOS's cron manager calls by URL (Requirements
 * 4.2, 4.5): `/cron/purge`, `/cron/sessions`, `/cron/keys`.
 *
 * None of these routes goes through session resolution or authorisation -
 * see public/index.php's cron pipeline - because a cron call carries no
 * session cookie at all and would otherwise be treated as an anonymous
 * request and redirected to the login page. Token authentication
 * ({@see CronAuth}) is this controller's own first step on every method
 * instead, which keeps the check next to the work it guards and keeps the
 * main pipeline's session/authorisation stages meant for browser requests
 * only.
 *
 * Every method does a single bounded slice of work and returns a small
 * plain-text summary; there is no template, no session, and nothing here
 * ever renders health data. Each slice size is chosen to stay well inside
 * the IONOS cron manager's 60-second cap even on a slow run, per the
 * design's "Cron endpoints" table.
 */
final class CronController
{
    /** `/cron/purge`: outstanding purge_jobs retried per run. */
    private const PURGE_LIMIT = 50;

    /** `/cron/sessions`: expired session rows deleted per run. */
    private const SESSIONS_LIMIT = 500;

    /** `/cron/keys`: rows re-encrypted onto the active key per run. */
    private const KEYS_LIMIT = 200;

    /**
     * How long a terminated or idle session row is kept before
     * `/cron/sessions` deletes it. Not specified by the design beyond
     * "beyond retention" (see design.md's Cron endpoints table), so this is
     * an explicit MVP choice: a terminated session's cookie is already
     * useless the moment `terminated_at` is set, and an idle session has
     * already failed to resolve well before this - Requirement 2.5's
     * 30-minute idle timeout runs at resolution time, independently of this
     * cron. Keeping rows for 24 hours past either point leaves a short
     * window for diagnosing an unexpected sign-out without holding rows any
     * longer than that.
     */
    private const SESSION_RETENTION_HOURS = 24;

    public function __construct(
        private readonly CronAuth $auth,
        private readonly PurgeService $purgeService,
        private readonly SessionRepository $sessions,
        private readonly KeyRotationService $keyRotation,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Daily: retry outstanding `purge_jobs`, up to a bounded number of users
     * per run (Requirement 4.5).
     */
    public function purge(Request $request): Response
    {
        if (!$this->auth->isAuthorised($request)) {
            return $this->auth->unauthorised();
        }

        $report = $this->purgeService->runPurgeSlice(self::PURGE_LIMIT);

        return self::plainText(sprintf(
            "purge: attempted=%d succeeded=%d failed=%d\n",
            $report->attempted,
            $report->succeeded,
            $report->failed,
        ));
    }

    /**
     * Hourly: delete session rows terminated or idle beyond retention
     * (Requirement 4.2's design table row for `/cron/sessions`).
     */
    public function sessions(Request $request): Response
    {
        if (!$this->auth->isAuthorised($request)) {
            return $this->auth->unauthorised();
        }

        $cutoff = $this->clock->now()->sub(new DateInterval('PT' . self::SESSION_RETENTION_HOURS . 'H'));
        $deleted = $this->sessions->deleteExpired($cutoff, self::SESSIONS_LIMIT);

        return self::plainText(sprintf("sessions: deleted=%d\n", $deleted));
    }

    /**
     * On demand: re-encrypt a bounded slice of rows onto the current active
     * DEK, then retire any old key left unreferenced (Requirement 4.2).
     */
    public function keys(Request $request): Response
    {
        if (!$this->auth->isAuthorised($request)) {
            return $this->auth->unauthorised();
        }

        $report = $this->keyRotation->runSlice(self::KEYS_LIMIT);

        return self::plainText(sprintf(
            "keys: re_encrypted=%d failed=%d retired_keys=%d\n",
            $report->reEncrypted,
            $report->failed,
            $report->retiredKeys,
        ));
    }

    private static function plainText(string $body): Response
    {
        return Response::html($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
