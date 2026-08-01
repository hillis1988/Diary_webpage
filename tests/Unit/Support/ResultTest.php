<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Support;

use Diary\Support\Result;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Services return a Result for expected failures, so each failure has to carry
 * both a code the caller branches on and the plain message the user is shown.
 */
final class ResultTest extends TestCase
{
    public function testASuccessCarriesItsValue(): void
    {
        $result = Result::ok('entry-id');

        self::assertTrue($result->isOk());
        self::assertFalse($result->isFailure());
        self::assertSame('entry-id', $result->value());
        self::assertSame('entry-id', $result->valueOr('fallback'));
        self::assertNull($result->errorCode());
        self::assertNull($result->message());
        self::assertSame([], $result->fieldMessages());
    }

    public function testASuccessNeedsNoValue(): void
    {
        $result = Result::ok();

        self::assertTrue($result->isOk());
        self::assertNull($result->value());
    }

    public function testAFailureCarriesACodeAMessageAndFieldMessages(): void
    {
        $result = Result::failure(
            'mood_rating_out_of_range',
            'Please give your mood a rating from 1 to 10.',
            ['mood_rating' => 'Choose a rating from 1 to 10.']
        );

        self::assertTrue($result->isFailure());
        self::assertFalse($result->isOk());
        self::assertTrue($result->hasErrorCode('mood_rating_out_of_range'));
        self::assertFalse($result->hasErrorCode('something_else'));
        self::assertSame('Please give your mood a rating from 1 to 10.', $result->message());
        self::assertSame('Choose a rating from 1 to 10.', $result->fieldMessage('mood_rating'));
        self::assertNull($result->fieldMessage('sleep_quality'));
        self::assertSame('fallback', $result->valueOr('fallback'));
    }

    public function testReadingTheValueOfAFailureIsAProgrammingError(): void
    {
        $result = Result::failure('email_already_registered', 'That email address already has an account.');

        $this->expectException(LogicException::class);

        $result->value();
    }

    public function testAFailureWithoutAnErrorCodeIsRejected(): void
    {
        $this->expectException(LogicException::class);

        Result::failure('', 'Something went wrong.');
    }

    public function testMapTransformsASuccessAndPassesAFailureThrough(): void
    {
        $mapped = Result::ok(2)->map(static fn (int $value): int => $value * 10);

        self::assertTrue($mapped->isOk());
        self::assertSame(20, $mapped->value());

        $failure = Result::failure('locked_out', 'Too many attempts. Try again in 15 minutes.');
        $untouched = $failure->map(static fn (mixed $value): string => 'never called');

        self::assertTrue($untouched->isFailure());
        self::assertTrue($untouched->hasErrorCode('locked_out'));
        self::assertSame('Too many attempts. Try again in 15 minutes.', $untouched->message());
    }
}
