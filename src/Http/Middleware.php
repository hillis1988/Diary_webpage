<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * One stage of the request pipeline.
 *
 * A stage may:
 *
 * - inspect the request and pass it on (`$next->handle($request)`);
 * - enrich it first (`$next->handle($request->withAttribute(...))`), which is how
 *   session resolution will hand the SecurityContext downstream;
 * - decorate the response coming back, which is how the security headers are added;
 * - or refuse to call `$next` at all and answer itself, which is how the TLS guard
 *   and the CSRF check stop a request before any handler runs.
 *
 * That last case is the whole reason the pipeline is built from an interface rather
 * than a list of callbacks over a mutable response: "no write happened" is provable
 * because the handler was never reached.
 */
interface Middleware
{
    public function process(Request $request, RequestHandler $next): Response;
}
