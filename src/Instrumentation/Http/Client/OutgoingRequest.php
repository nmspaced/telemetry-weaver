<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;

/**
 * The parts of an outgoing request known before it is sent: method, scheme, host and port.
 *
 * They form the histogram's label set, which is frozen when the measurement starts. A URL
 * that cannot be parsed yields null and the request goes out uninstrumented.
 */
final readonly class OutgoingRequest
{
    private function __construct(
        public HttpMethod $method,
        public string $scheme,
        public string $host,
        public int $port,
    ) {}

    /**
     * @param string|null $baseUri what a relative URL is resolved against, when the
     *                             decorated client resolves one at all
     */
    public static function from(string $method, string $url, ?string $baseUri = null): ?self
    {
        $target = self::parse($url);

        if ($target === null) {
            return null;
        }

        $base = self::parse($baseUri ?? '') ?? [];

        $authority = ($target['host'] ?? null) === null ? $base : $target;

        $scheme = self::lower($target['scheme'] ?? $base['scheme'] ?? '');
        $host = self::lower($authority['host'] ?? '');

        $defaultPort = self::defaultPort($scheme);

        if ($host === '' || $defaultPort === null) {
            return null;
        }

        return new self(HttpMethod::fromString($method), $scheme, $host, $authority['port'] ?? $defaultPort);
    }

    /**
     * The metric label set. Its cardinality grows with the number of hosts the application
     * calls; clients that call arbitrary hosts need an SDK view or delta temporality.
     *
     * @return array<non-empty-string, string|int>
     */
    public function metricAttributes(): array
    {
        return [
            HttpAttributes::HTTP_REQUEST_METHOD => $this->method->value,
            ServerAttributes::SERVER_ADDRESS => $this->host,
            ServerAttributes::SERVER_PORT => $this->port,
            UrlAttributes::URL_SCHEME => $this->scheme,
        ];
    }

    /**
     * The metric labels plus the original method spelling, which is too unbounded for a label.
     *
     * @return array<non-empty-string, string|int>
     */
    public function spanAttributes(): array
    {
        $attributes = $this->metricAttributes();

        if ($this->method->hasDistinctOriginal()) {
            $attributes[HttpAttributes::HTTP_REQUEST_METHOD_ORIGINAL] = $this->method->original;
        }

        return $attributes;
    }

    /**
     * @return array{
     *     scheme?: string,
     *     host?: string,
     *     port?: int,
     *     user?: string,
     *     pass?: string,
     *     path?: string,
     *     query?: string,
     *     fragment?: string
     * }|null null when the URL is malformed beyond parsing
     */
    private static function parse(string $url): ?array
    {
        $parts = \parse_url($url);

        return $parts === false ? null : $parts;
    }

    private static function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    private static function lower(string $value): string
    {
        return \strtolower($value);
    }
}
