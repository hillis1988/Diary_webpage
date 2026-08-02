<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * Anything that can turn a request into a response: the router, a single route
 * handler, or the remainder of the middleware chain seen from one stage.
 */
interface RequestHandler
{
    public function handle(Request $request): Response;
}
