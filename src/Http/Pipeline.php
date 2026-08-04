<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * The middleware chain, run in one fixed order for every request.
 *
 * The order is the design's request pipeline and it is not configurable per route,
 * because a route that could opt out of authorisation is a route that will
 * eventually opt out by accident:
 *
 *   1. HTTPS redirect        - nothing else runs on a plaintext connection
 *   2. Security headers      - present on every response, including the redirect above
 *   3. CSRF check            - a state-changing request with no fresh token never reaches a handler
 *   4. Session resolution    - produces the SecurityContext ({@see SessionResolverMiddleware})
 *   5. Authorisation         - the permission matrix decides (task 8.1)
 *   6. Handler               - the router, and only then a controller
 *
 * Authorisation is not built yet. {@see fixedOrder()} takes both of the middle stages
 * as nullable arguments so they slot into their place when they arrive, rather than
 * being appended wherever there happens to be room.
 */
final class Pipeline implements RequestHandler
{
    /** @var list<Middleware> */
    private readonly array $middleware;

    /**
     * @param list<Middleware> $middleware run outermost first
     */
    public function __construct(array $middleware, private readonly RequestHandler $handler)
    {
        $this->middleware = array_values($middleware);
    }

    /**
     * Assemble the pipeline in the fixed order above.
     *
     * Passing the stages as named parameters rather than as a list means a caller
     * cannot reorder them: the only freedom is whether the two unbuilt stages are
     * present yet.
     */
    public static function fixedOrder(
        HttpsRedirectMiddleware $httpsRedirect,
        SecurityHeadersMiddleware $securityHeaders,
        CsrfMiddleware $csrf,
        ?Middleware $sessionResolution,
        ?Middleware $authorisation,
        RequestHandler $handler,
    ): self {
        $stages = [$httpsRedirect, $securityHeaders, $csrf];

        if ($sessionResolution !== null) {
            $stages[] = $sessionResolution;
        }

        if ($authorisation !== null) {
            $stages[] = $authorisation;
        }

        return new self($stages, $handler);
    }

    public function handle(Request $request): Response
    {
        return $this->stageAt(0)->handle($request);
    }

    /**
     * The remainder of the chain from `$index` onwards, as something that satisfies
     * {@see RequestHandler}. Built lazily so a stage that never calls `$next` costs
     * nothing beyond itself.
     */
    private function stageAt(int $index): RequestHandler
    {
        if (!isset($this->middleware[$index])) {
            return $this->handler;
        }

        $middleware = $this->middleware[$index];
        $next = new class ($this, $index + 1) implements RequestHandler {
            public function __construct(private readonly Pipeline $pipeline, private readonly int $index)
            {
            }

            public function handle(Request $request): Response
            {
                return $this->pipeline->continueAt($this->index, $request);
            }
        };

        return new class ($middleware, $next) implements RequestHandler {
            public function __construct(private readonly Middleware $middleware, private readonly RequestHandler $next)
            {
            }

            public function handle(Request $request): Response
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }

    /**
     * @internal used by the anonymous handler above to walk to the next stage
     */
    public function continueAt(int $index, Request $request): Response
    {
        return $this->stageAt($index)->handle($request);
    }
}
