<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

/**
 * The summary and query text of a statement, remembered per statement for the process.
 *
 * The summary is the costly part — two large regular expressions, 3 to 7 µs for an ORM
 * statement — and both are pure functions of the SQL, which the ORM and DBAL send verbatim
 * over and over with only the parameters changing. A repeated statement is a hash and a lookup.
 *
 * Bounded process state: at most {@see self::CAPACITY} entries, the oldest dropped first.
 * Keys are hashes, so raw SQL — literals and all — is never held beyond the call, and the
 * values hold no literal either: the sanitized text has them replaced, and `raw` query text is
 * the SQL itself, never stored here.
 *
 * @internal
 */
final class SqlDescriptions
{
    private const int CAPACITY = 256;

    /** @var array<string, array{?non-empty-string, ?string}> hash => [summary, sanitized text] */
    private array $described = [];

    /**
     * @return array{?non-empty-string, ?string} the summary, and the sanitized text when asked for
     */
    public function describe(string $sql, string $system, bool $sanitize): array
    {
        $key = \hash('xxh128', $system . "\0" . ($sanitize ? 's' : '-') . $sql);

        $remembered = $this->described[$key] ?? null;

        if ($remembered !== null) {
            return $remembered;
        }

        $code = SqlLexer::code($sql, $system);
        $description = [SqlSummary::fromCode($code)->value, $sanitize ? SqlQueryText::sanitized($code) : null];

        if (\count($this->described) >= self::CAPACITY) {
            unset($this->described[\array_key_first($this->described)]);
        }

        return $this->described[$key] = $description;
    }
}
