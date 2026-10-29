<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Doctrine;

use OpenTelemetry\SemConv\Attributes\CodeAttributes;

/**
 * The application code a query came from: the first frame on the stack outside Composer's
 * vendor directory, this bundle's own sources and the excluded paths, which are generated code
 * such as Doctrine proxies and the compiled container.
 *
 * `code.file.path` and `code.line.number` are where that code called into the library, and
 * `code.function.name` the function that line belongs to — the repository method, typically.
 * A query whose stack never leaves the excluded paths, such as a Messenger transport polling,
 * gets nothing.
 *
 * @internal
 */
final readonly class QueryCaller
{
    /**
     * Deep enough for an ORM query issued from a controller; a stack deeper than this inside
     * the ORM is not worth the time it takes to walk.
     */
    private const int MAX_FRAMES = 64;

    /** What PHP reports as the function of a frame that is a file being included. */
    private const array INCLUDES = ['include', 'include_once', 'require', 'require_once'];

    private LibraryPaths $libraryPaths;

    /**
     * @param list<string> $excludedPaths directories whose frames are never the caller, beside
     *     the vendor directory and this bundle's sources, which always are excluded
     */
    public function __construct(array $excludedPaths = [])
    {
        $this->libraryPaths = new LibraryPaths($excludedPaths);
    }

    /**
     * @return array<non-empty-string, string|int>
     */
    public function attributes(): array
    {
        /** @var list<array{function: string, line?: int, file?: string, class?: class-string}> $frames */
        $frames = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES);

        foreach ($frames as $index => $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || $this->libraryPaths->contains($file)) {
                continue;
            }

            $attributes = [CodeAttributes::CODE_FILE_PATH => $file];

            $line = $frame['line'] ?? null;

            if ($line !== null) {
                $attributes[CodeAttributes::CODE_LINE_NUMBER] = $line;
            }

            $function = self::function($frames[$index + 1] ?? null);

            if ($function !== null) {
                $attributes[CodeAttributes::CODE_FUNCTION_NAME] = $function;
            }

            return $attributes;
        }

        return [];
    }

    /**
     * The function the calling line belongs to; null at the top of a script.
     *
     * @param array{function: string, line?: int, file?: string, class?: class-string}|null $frame
     *
     * @return non-empty-string|null
     */
    private static function function(?array $frame): ?string
    {
        if ($frame === null || $frame['function'] === '' || \in_array($frame['function'], self::INCLUDES, true)) {
            return null;
        }

        $class = $frame['class'] ?? null;

        return $class === null ? $frame['function'] : \sprintf('%s::%s', $class, $frame['function']);
    }
}
