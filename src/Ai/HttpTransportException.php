<?php

declare(strict_types=1);

namespace Diary\Ai;

use RuntimeException;

/**
 * A network-level failure raised by an {@see HttpTransport}: DNS failure, a
 * refused connection, a TLS failure, or the request exceeding its timeout.
 *
 * This is caught inside {@see HttpsFeedbackProvider} and turned into a
 * {@see ProviderError} once the retry is exhausted; it never escapes the
 * adapter on its own.
 */
final class HttpTransportException extends RuntimeException
{
}
