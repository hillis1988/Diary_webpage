<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * Stage 3: a state-changing request without a fresh, matching CSRF token is
 * answered here and never reaches a handler.
 *
 * "No write happened" is structural rather than promised: the stage returns its own
 * response instead of calling `$next`, so the router is not consulted, no service is
 * constructed and no statement is executed. That is the design's error-catalogue row
 * for a missing or stale token - reject the request, no write - and the reason the
 * check sits above session resolution and authorisation rather than inside a
 * controller.
 *
 * GET and HEAD are not checked. They are required to be side-effect free, and
 * demanding a token on a plain page load would break every ordinary link.
 *
 * The verdict is attached to the request as an attribute even when it is refused,
 * so an audit or logging stage can record which case occurred; the page itself says
 * only {@see FORM_EXPIRED_MESSAGE}.
 */
final class CsrfMiddleware implements Middleware
{
    /** The design's error catalogue wording for a missing or stale token. */
    public const FORM_EXPIRED_MESSAGE = 'That form expired. Please try again';

    public const HEADING = 'That form expired';

    /** 419 is not standard; 403 is the honest status for "refused, not retryable as sent". */
    public const REJECTED_STATUS = 403;

    public const VERDICT_ATTRIBUTE = 'csrf.verdict';

    public function __construct(private readonly CsrfGuard $guard)
    {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        if (!$request->isStateChanging()) {
            return $next->handle($request);
        }

        $verdict = $this->guard->verify($request);

        if (!$verdict->isValid()) {
            return StatusPage::response(
                self::REJECTED_STATUS,
                self::HEADING,
                self::FORM_EXPIRED_MESSAGE
            );
        }

        return $next->handle($request->withAttribute(self::VERDICT_ATTRIBUTE, $verdict));
    }
}
