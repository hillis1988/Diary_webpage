<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * Splits a migration file into individual statements.
 *
 * The runner issues one statement per driver call instead of relying on
 * multi-statement support, which keeps the connection hardening in
 * ConnectionFactory intact and makes the runner behave the same on MariaDB and
 * on any other PDO driver an integration test happens to use.
 *
 * The splitter understands single quotes, double quotes, backticks, backslash
 * escapes inside quoted strings, `--` and `#` line comments and `/* *\/` block
 * comments, so a semicolon inside any of those is not a statement boundary.
 *
 * It deliberately does not understand compound bodies (`BEGIN ... END` in a
 * trigger or stored procedure), because splitting those correctly needs a
 * delimiter directive. No migration in this project uses one; if one ever does,
 * it needs its own handling rather than a looser splitter here.
 */
final class SqlStatementSplitter
{
    /**
     * @return list<string> statements in file order, comments and blank runs removed
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    // Escaped character inside a string: consume it verbatim.
                    $current .= $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    // A doubled quote is an escaped quote, not the end of the string.
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '-' && $next === '-') {
                $i = self::skipToEndOfLine($sql, $i);
                $current .= "\n";
                continue;
            }

            if ($char === '#') {
                $i = self::skipToEndOfLine($sql, $i);
                $current .= "\n";
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $current .= ' ';
                continue;
            }

            if ($char === ';') {
                $statements = self::append($statements, $current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        // A trailing statement without a closing semicolon still counts.
        return self::append($statements, $current);
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private static function append(array $statements, string $candidate): array
    {
        $trimmed = trim($candidate);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * @return int the index of the newline (or the end of input) so the loop's $i++ moves past it
     */
    private static function skipToEndOfLine(string $sql, int $from): int
    {
        $newline = strpos($sql, "\n", $from);

        return $newline === false ? strlen($sql) : $newline;
    }
}
