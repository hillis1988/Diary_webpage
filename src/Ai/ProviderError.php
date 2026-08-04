<?php

declare(strict_types=1);

namespace Diary\Ai;

use RuntimeException;

/**
 * Raised by a {@see FeedbackProvider} or summary provider when the LLM call
 * could not be completed: a network-level failure, a non-2xx response, a
 * response body that is not valid JSON, or a strict-JSON timeout after the
 * configured retry has been exhausted.
 *
 * This is always the failure signal AI_Feedback_Service and AI_Summary_Service
 * catch to fall back to their "unavailable" outcome (Requirement 6.5); the
 * diary entry itself is never blocked by it. Messages never carry the API key
 * or diary content, only what went wrong with the transport.
 */
final class ProviderError extends RuntimeException
{
}
