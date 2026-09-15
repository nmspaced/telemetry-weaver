<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * `normalized_headers` is how `HttpClientTrait` reports the headers a mocked request actually
 * carried, keyed by lower-cased header name. Centralizing the cast and the lookups keeps every
 * client-instrumentation test from repeating an unchecked array access.
 *
 * @internal
 */
final class HttpHeaders
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<string, list<string>>
     */
    public static function normalized(array $options): array
    {
        /** @var array<string, list<string>> */
        return $options['normalized_headers'] ?? [];
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return list<string>
     */
    public static function values(array $headers, string $name): array
    {
        return $headers[$name] ?? Assert::fail('missing header: ' . $name);
    }

    /** @param array<string, list<string>> $headers */
    public static function value(array $headers, string $name, int $index = 0): string
    {
        return (
            self::values($headers, $name)[$index] ?? Assert::fail('missing header value: ' . $name . '[' . $index . ']')
        );
    }
}
