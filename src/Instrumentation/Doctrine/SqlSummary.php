<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * `db.query.summary`: the statement's operations and targets in order, capped at 255
 * characters without cutting a token. Null for unrecognised statements or ones the lexer
 * refused. It names the span and is never used as a metric label.
 */
final readonly class SqlSummary
{
    /** The conventions' cap for a parsed summary. */
    private const int MAX_LENGTH = 255;

    /**
     * Leading keywords that get a summary.
     *
     * @var list<non-empty-string>
     */
    private const array STATEMENTS = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'MERGE',
        'REPLACE',
        'WITH',
        'CREATE',
        'ALTER',
        'DROP',
        'TRUNCATE',
        'CALL',
        'SAVEPOINT',
        'RELEASE',
    ];

    /** The first word of the lexed code. */
    private const string LEADING = '~^\s*+(?<statement>[a-z]++)~i';

    /**
     * @param non-empty-string|null $value
     */
    private function __construct(
        public ?string $value,
    ) {}

    /**
     * @param string $system the connection's `db.system.name`
     */
    public static function of(string $sql, string $system): self
    {
        return self::fromCode(SqlLexer::code($sql, $system));
    }

    /**
     * @param string|null $code the output of {@see SqlLexer::code()}
     */
    public static function fromCode(?string $code): self
    {
        if ($code === null || !self::isDescribable($code)) {
            return new self(null);
        }

        $tokens = [];
        $length = -1;

        foreach (SqlScanner::tokens($code) as $token) {
            $length += \strlen($token) + 1;

            if ($length > self::MAX_LENGTH) {
                break;
            }

            $tokens[] = $token;
        }

        if ($tokens === []) {
            return new self(null);
        }

        return new self(\implode(' ', $tokens));
    }

    private static function isDescribable(string $sql): bool
    {
        $matches = [];

        if (\preg_match(self::LEADING, $sql, $matches) !== 1) {
            return false;
        }

        /** @var array{0: non-empty-string, statement: non-empty-string, 1: non-empty-string} $matches */
        return \in_array(\strtoupper($matches['statement']), self::STATEMENTS, true);
    }
}
