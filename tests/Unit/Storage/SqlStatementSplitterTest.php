<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

/**
 * The runner sends one statement per driver call, so the splitter has to agree
 * with the SQL actually written in migrations/: leading comment blocks, quoted
 * strings and trailing semicolons all appear there.
 */
final class SqlStatementSplitterTest extends TestCase
{
    public function testASingleStatementSurvivesWithoutItsSemicolon(): void
    {
        self::assertSame(['CREATE TABLE t (id INT)'], SqlStatementSplitter::split('CREATE TABLE t (id INT);'));
    }

    public function testAStatementWithoutATrailingSemicolonIsStillReturned(): void
    {
        self::assertSame(['SELECT 1'], SqlStatementSplitter::split("SELECT 1\n"));
    }

    public function testMultipleStatementsAreSplitInOrder(): void
    {
        $sql = "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);\n";

        self::assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            SqlStatementSplitter::split($sql)
        );
    }

    public function testLineCommentsAreStripped(): void
    {
        $sql = <<<'SQL'
            -- 001_create_users.sql
            --
            -- Requirements: 4.1
            CREATE TABLE users (id CHAR(26)); # trailing note
            SQL;

        self::assertSame(['CREATE TABLE users (id CHAR(26))'], SqlStatementSplitter::split($sql));
    }

    public function testBlockCommentsAreStripped(): void
    {
        $sql = "/* a note ; with a semicolon */ SELECT 1; /* another */";

        self::assertSame(['SELECT 1'], SqlStatementSplitter::split($sql));
    }

    public function testSemicolonsInsideQuotedStringsAreNotBoundaries(): void
    {
        $sql = "INSERT INTO t (v) VALUES ('a;b'); INSERT INTO t (v) VALUES (\"c;d\");";

        self::assertSame(
            ["INSERT INTO t (v) VALUES ('a;b')", 'INSERT INTO t (v) VALUES ("c;d")'],
            SqlStatementSplitter::split($sql)
        );
    }

    public function testCommentMarkersInsideQuotedStringsAreKept(): void
    {
        $sql = "INSERT INTO t (v) VALUES ('-- not a comment');";

        self::assertSame(["INSERT INTO t (v) VALUES ('-- not a comment')"], SqlStatementSplitter::split($sql));
    }

    public function testBacktickIdentifiersAreKeptIntact(): void
    {
        $sql = 'SELECT `weird;name` FROM t;';

        self::assertSame(['SELECT `weird;name` FROM t'], SqlStatementSplitter::split($sql));
    }

    public function testEscapedAndDoubledQuotesDoNotEndTheString(): void
    {
        $sql = "INSERT INTO t (v) VALUES ('it\\'s; fine'); INSERT INTO t (v) VALUES ('it''s; fine');";

        self::assertSame(
            ["INSERT INTO t (v) VALUES ('it\\'s; fine')", "INSERT INTO t (v) VALUES ('it''s; fine')"],
            SqlStatementSplitter::split($sql)
        );
    }

    public function testCommentOnlyAndEmptyInputYieldNoStatements(): void
    {
        self::assertSame([], SqlStatementSplitter::split(''));
        self::assertSame([], SqlStatementSplitter::split("-- nothing here\n;;\n"));
    }

    public function testEveryRealMigrationSplitsIntoAtLeastOneStatement(): void
    {
        $paths = glob(__DIR__ . '/../../../migrations/*.sql');
        self::assertNotFalse($paths);
        self::assertNotEmpty($paths);

        foreach ($paths as $path) {
            $sql = file_get_contents($path);
            self::assertIsString($sql);

            $statements = SqlStatementSplitter::split($sql);

            self::assertNotEmpty($statements, basename($path) . ' produced no statements');

            foreach ($statements as $statement) {
                self::assertStringNotContainsString('--', $statement, basename($path) . ' kept a line comment');
            }
        }
    }
}
