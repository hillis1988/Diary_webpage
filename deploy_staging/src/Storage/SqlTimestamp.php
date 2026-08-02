<?php

declare(strict_types=1);

namespace Diary\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The one shape a DATETIME column is written in.
 *
 * Every DATETIME in the schema is a naive `Y-m-d H:i:s` carrying no zone of its
 * own, and the application treats them all as UTC. Formatting and parsing live
 * here so that a row written by one repository reads back identically through
 * another, and so a value that arrived in a different zone is converted rather
 * than stored as-is.
 */
final class SqlTimestamp
{
    public const FORMAT = 'Y-m-d H:i:s';

    private function __construct()
    {
    }

    public static function format(?DateTimeInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        $immutable = $moment instanceof DateTimeImmutable
            ? $moment
            : DateTimeImmutable::createFromInterface($moment);

        return $immutable->setTimezone(self::utc())->format(self::FORMAT);
    }

    /**
     * Read a stored value back. Anything that is not a non-empty string - a NULL
     * column, most obviously - is absent rather than an error.
     */
    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        // The leading '!' resets the unparsed fields, so a value without a time
        // part becomes midnight rather than inheriting the current clock.
        $parsed = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, self::utc());

        return $parsed === false ? new DateTimeImmutable($value, self::utc()) : $parsed;
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}
