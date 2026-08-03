<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\CbtAdvice;
use PHPUnit\Framework\TestCase;

/**
 * CbtAdvice is a plain four-field carrier (Requirement 5.4): every accessor
 * returns exactly the value it was constructed with.
 */
final class CbtAdviceTest extends TestCase
{
    public function testAccessorsReturnExactlyTheConstructedValues(): void
    {
        $advice = new CbtAdvice(
            'All-or-nothing thinking about missed workouts',
            'Black-and-white thinking, overgeneralization',
            'Missing one workout does not undo the whole month of progress',
            'Plan the next workout instead of dwelling on the missed one',
        );

        self::assertSame('All-or-nothing thinking about missed workouts', $advice->pattern());
        self::assertSame('Black-and-white thinking, overgeneralization', $advice->distortions());
        self::assertSame(
            'Missing one workout does not undo the whole month of progress',
            $advice->balancedPerspective()
        );
        self::assertSame(
            'Plan the next workout instead of dwelling on the missed one',
            $advice->nextAction()
        );
    }
}
