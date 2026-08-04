<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * Stage 2: put the fixed security headers on whatever comes back.
 *
 * It decorates the response on the way out rather than the request on the way in,
 * so a page, a redirect, a 403, a 404 and an error page are all covered by the same
 * three lines - there is no path through the application that can forget them.
 *
 * The one response this stage never sees is the TLS guard's redirect, because that
 * guard sits outside it and answers without calling `$next`. The guard therefore
 * applies {@see SecurityHeaders} itself, from the same constants.
 */
final class SecurityHeadersMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $next): Response
    {
        return SecurityHeaders::applyTo($next->handle($request));
    }
}
