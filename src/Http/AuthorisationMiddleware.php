<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Support\Operation;
use Diary\Support\OperationKind;

/**
 * Stage 5: turn the {@see SecurityContext} session resolution produced into a
 * {@see Decision} from {@see AccessControlService}, and turn that decision into a
 * response.
 *
 * This is the pipeline's only place where "may this request proceed?" is asked;
 * everything else about the answer - the redirect target, the denial message, the
 * status code - already lives on the {@see Decision} and is not re-decided here.
 * A denial or a redirect returns its own response instead of calling `$next`, so
 * the router and every handler beneath it never run (Requirement 2.6): "no write
 * happened past this point" is structural, the same guarantee the CSRF stage gives
 * for a stale token.
 *
 * What operation a request represents is a route-to-operation mapping that belongs
 * to each controller (later tasks build it one route at a time), so this stage
 * takes an operation resolver rather than deciding on a path itself:
 *
 * - the default, {@see defaultOperationResolver()}, is deliberately coarse: a safe
 *   method (GET/HEAD) is a diary-data read of the requested path, and a
 *   state-changing method is treated as the most conservative mutating kind
 *   (`WriteDiaryEntry`). The permission matrix already redirects an anonymous
 *   caller and denies a viewer for every mutating kind alike, so this default is
 *   exactly as strict as a precise mapping would be for those two roles; only the
 *   audit trail's action label is generic until a route names itself properly;
 * - a caller that already knows the real operation for its routes - or the
 *   pipeline built with a router in place - can pass its own resolver instead.
 */
final class AuthorisationMiddleware implements Middleware
{
    /** Where the resolved operation is attached, for a handler or an audit hook that wants it. */
    public const OPERATION_ATTRIBUTE = 'authorisation.operation';

    public const DENIED_HEADING = 'Access denied';

    /** @var callable(Request): Operation */
    private readonly \Closure $operationResolver;

    /**
     * @param callable(Request): Operation|null $operationResolver defaults to
     *                                                              {@see defaultOperationResolver()}
     */
    public function __construct(
        private readonly AccessControlService $access,
        ?callable $operationResolver = null,
    ) {
        $this->operationResolver = $operationResolver !== null
            ? \Closure::fromCallable($operationResolver)
            : \Closure::fromCallable([self::class, 'defaultOperationResolver']);
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);
        $operation = ($this->operationResolver)($request);

        $decision = $this->access->authorise($context, $operation);

        if ($decision->isAllowed()) {
            return $next->handle($request->withAttribute(self::OPERATION_ATTRIBUTE, $operation));
        }

        if ($decision->isRedirectToLogin()) {
            return Response::redirect((string) $decision->location(), Decision::REDIRECT_STATUS);
        }

        return StatusPage::response(
            Decision::DENIED_STATUS,
            self::DENIED_HEADING,
            (string) $decision->message()
        );
    }

    /**
     * The coarse, first-pass mapping described on the class. The login and
     * registration paths are always {@see OperationKind::ViewAuthPage} - the one
     * kind every role, including anonymous, is allowed - since treating them as
     * an ordinary protected read would redirect an anonymous caller to the login
     * page from the login page itself. Every other safe request reads the
     * requested path's diary data; every state-changing request is treated as a
     * diary-entry mutation. Neither reads any route table - there isn't one yet -
     * so this is intentionally not the final word on what a path does, only the
     * strictest reasonable default until each controller supplies its own
     * resolver.
     */
    public static function defaultOperationResolver(Request $request): Operation
    {
        if (AccessControlService::isPublicPath($request->path)) {
            return Operation::of(OperationKind::ViewAuthPage, 'http.view_auth_page:' . $request->path, $request->pathWithQuery());
        }

        if (!$request->isStateChanging()) {
            return Operation::readDiaryData('http.read:' . $request->path, $request->pathWithQuery());
        }

        return Operation::of(OperationKind::WriteDiaryEntry, 'http.mutate:' . $request->path, $request->pathWithQuery());
    }
}
