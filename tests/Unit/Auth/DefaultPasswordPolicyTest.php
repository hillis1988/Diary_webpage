<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 1.5 fixes the rules (>= 12 characters, >= 1 letter, >= 1 digit) and
 * Requirement 1.3 fixes what the user is told when a password breaks them. Both the
 * boundary at 12 characters and the exact rejection wording are pinned here.
 */
final class DefaultPasswordPolicyTest extends TestCase
{
    private DefaultPasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DefaultPasswordPolicy();
    }

    public function testAcceptsAPasswordMeetingEveryRule(): void
    {
        $result = $this->policy->validate('correct1horse2battery');

        self::assertTrue($result->isOk());
        self::assertNull($result->value());
    }

    public function testAcceptsExactlyTwelveCharactersAndRejectsEleven(): void
    {
        self::assertTrue($this->policy->validate('abcdefghijk1')->isOk(), 'twelve characters is the accepted boundary');
        self::assertTrue($this->policy->validate('1abcdefghijk')->isOk());

        self::assertTrue($this->policy->validate('abcdefghij1')->isFailure(), 'eleven characters is one short');
    }

    public function testRejectsAPasswordWithNoDigit(): void
    {
        self::assertTrue($this->policy->validate('abcdefghijklmnop')->isFailure());
    }

    public function testRejectsAPasswordWithNoLetter(): void
    {
        self::assertTrue($this->policy->validate('1234567890123456')->isFailure());
    }

    public function testRejectsAnEmptyPassword(): void
    {
        self::assertTrue($this->policy->validate('')->isFailure());
    }

    public function testWhitespaceAndSymbolsCountTowardsLengthAndAreNotBanned(): void
    {
        self::assertTrue($this->policy->validate('my cat is 9!')->isOk());
        self::assertTrue($this->policy->validate("\t\t\t\t\t\t\t\t\t\ta1")->isOk());
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // Twelve characters, twenty-three bytes: a byte count would wrongly pass a short password.
        self::assertTrue($this->policy->validate('éléphant1234')->isOk());

        // Eleven characters that occupy more than twelve bytes.
        self::assertTrue($this->policy->validate('éléphant123')->isFailure());
    }

    public function testNonAsciiLettersAndDigitsSatisfyTheirRules(): void
    {
        self::assertTrue($this->policy->validate('Привет1мирок')->isOk());
        self::assertTrue($this->policy->validate('пароль٣٣٣٣٣٣')->isOk());
    }

    public function testRejectionCarriesThePolicyDescriptionAsTheUserFacingMessage(): void
    {
        $result = $this->policy->validate('short');

        self::assertTrue($result->hasErrorCode(PasswordPolicy::ERROR_CODE));
        self::assertSame($this->policy->describe(), $result->message());
        self::assertSame($this->policy->describe(), $result->fieldMessage(PasswordPolicy::FIELD));
    }

    public function testDescribeStatesEveryRuleInTheAgreedWording(): void
    {
        self::assertSame(
            'Your password must be at least 12 characters long and include at least one letter and at least one digit.',
            $this->policy->describe()
        );
        self::assertSame(12, DefaultPasswordPolicy::MINIMUM_LENGTH);
    }

    public function testAcceptanceCarriesNoErrorDetail(): void
    {
        $result = $this->policy->validate('abcdefghijk1');

        self::assertNull($result->errorCode());
        self::assertNull($result->message());
        self::assertSame([], $result->fieldMessages());
    }
}
