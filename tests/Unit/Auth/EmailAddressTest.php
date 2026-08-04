<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Auth\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 1.2 rests on exactly two things: the normalised form used for the
 * uniqueness check, and the display form kept as typed.
 */
final class EmailAddressTest extends TestCase
{
    public function testNormalisationTrimsAndLowercasesWhileDisplayKeepsWhatWasTyped(): void
    {
        $address = EmailAddress::fromInput("  Roy.Hillis@Example.COM\t");

        self::assertSame('roy.hillis@example.com', $address->normalized());
        self::assertSame("  Roy.Hillis@Example.COM\t", $address->display());
    }

    public function testVariationsInCaseAndSurroundingSpaceShareOneNormalisedForm(): void
    {
        $forms = ['roy@example.com', 'ROY@EXAMPLE.COM', ' Roy@Example.com ', "\nroy@EXAMPLE.com\r\n"];

        foreach ($forms as $form) {
            self::assertSame('roy@example.com', EmailAddress::normalise($form));
        }
    }

    public function testNormalisationChangesNothingElseAboutTheAddress(): void
    {
        // Dots and +tags can address different mailboxes, so they survive untouched.
        self::assertSame('roy.h+diary@example.com', EmailAddress::normalise('Roy.H+Diary@Example.com'));
    }

    public function testAcceptsAnOrdinaryAddress(): void
    {
        self::assertTrue(EmailAddress::fromInput(' Roy@Example.com ')->isValid());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'no at sign' => ['royexample.com'];
        yield 'no domain' => ['roy@'];
        yield 'no local part' => ['@example.com'];
        yield 'inner space' => ['roy hillis@example.com'];
        yield 'too long' => [str_repeat('a', 250) . '@example.com'];
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsAddressesThatCannotBeStoredOrDelivered(string $raw): void
    {
        self::assertFalse(EmailAddress::fromInput($raw)->isValid());
    }
}
