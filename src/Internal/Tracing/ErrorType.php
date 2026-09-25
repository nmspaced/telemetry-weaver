<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * An `error.type` value as the conventions accept it: a non-empty string, or nothing.
 *
 * @internal
 */
final readonly class ErrorType
{
    /** @return non-empty-string|null */
    public static function from(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
