<?php

declare(strict_types=1);

namespace Diary\Support;

use LogicException;

/**
 * The outcome of an operation that can fail for an expected reason.
 *
 * Services return a Result rather than throwing for validation failures, so
 * every failure carries two things: a machine-readable code the caller can
 * branch on, and the short, plain, non-technical message the user is shown.
 * Field-level messages let a form name each offending field (Requirements 5.5,
 * 10.4) without inventing a second error shape.
 *
 * @template T
 */
final class Result
{
    /**
     * @param T|null                $value
     * @param array<string, string> $fieldMessages field name => user-facing message
     */
    private function __construct(
        private readonly bool $ok,
        private readonly mixed $value,
        private readonly ?string $errorCode,
        private readonly ?string $message,
        private readonly array $fieldMessages,
    ) {
    }

    /**
     * @template TValue
     *
     * @param TValue $value
     *
     * @return self<TValue>
     */
    public static function ok(mixed $value = null): self
    {
        return new self(true, $value, null, null, []);
    }

    /**
     * @param string                $errorCode machine-readable, e.g. 'email_already_registered'
     * @param string                $message   the exact text shown to the user
     * @param array<string, string> $fieldMessages
     *
     * @return self<null>
     */
    public static function failure(string $errorCode, string $message, array $fieldMessages = []): self
    {
        if ($errorCode === '') {
            throw new LogicException('A failure Result needs a machine-readable error code.');
        }

        return new self(false, null, $errorCode, $message, $fieldMessages);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isFailure(): bool
    {
        return !$this->ok;
    }

    /**
     * @return T
     */
    public function value(): mixed
    {
        if (!$this->ok) {
            throw new LogicException(sprintf(
                'Cannot read the value of a failed Result (%s).',
                (string) $this->errorCode
            ));
        }

        return $this->value;
    }

    /**
     * @template TDefault
     *
     * @param TDefault $default
     *
     * @return T|TDefault
     */
    public function valueOr(mixed $default): mixed
    {
        return $this->ok ? $this->value : $default;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The user-facing message; null on success.
     */
    public function message(): ?string
    {
        return $this->message;
    }

    public function hasErrorCode(string $errorCode): bool
    {
        return $this->errorCode === $errorCode;
    }

    /**
     * @return array<string, string>
     */
    public function fieldMessages(): array
    {
        return $this->fieldMessages;
    }

    public function fieldMessage(string $field): ?string
    {
        return $this->fieldMessages[$field] ?? null;
    }

    /**
     * Transform a success value, passing a failure through untouched.
     *
     * @template TNext
     *
     * @param callable(T): TNext $transform
     *
     * @return self<TNext>|self<null>
     */
    public function map(callable $transform): self
    {
        if (!$this->ok) {
            return $this;
        }

        return self::ok($transform($this->value));
    }
}
