<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * One pass/fail line from the post-deploy transport smoke check
 * ({@see TransportChecks}, `tools/smoke_check_transport.php`).
 *
 * Kept separate from the script so the checking logic - which headers must be
 * present, what a redirect must look like - is pure code that a unit test can
 * exercise with synthetic data, while the script itself only does the network
 * calls and prints these results (Requirement 4.3, verified by this script and
 * manual release review rather than by a property, per design.md).
 */
final class TransportCheckResult
{
    private function __construct(
        private readonly string $name,
        private readonly bool $passed,
        private readonly string $detail,
    ) {
    }

    public static function pass(string $name, string $detail = ''): self
    {
        return new self($name, true, $detail);
    }

    public static function fail(string $name, string $detail): self
    {
        return new self($name, false, $detail);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    /**
     * Empty on a plain pass; the reason on a failure, or extra context on a pass.
     */
    public function detail(): string
    {
        return $this->detail;
    }
}
