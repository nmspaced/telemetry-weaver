<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\HttpMethod;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;

/**
 * The part of an outgoing request that is known before it is sent.
 *
 * Only what the required attributes need: `server.address`, `server.port` and
 * `url.scheme` have to be on both the span and the histogram, and they have to be there
 * from the start, because the histogram's label set is frozen when the measurement
 * begins. The URL itself is not resolved here — it is read back from the response once
 * the client has resolved it, which is the only spelling that is certain to match what
 * actually went on the wire.
 *
 * Parsing is for telemetry only. Symfony remains responsible for validating the URL and
 * for deciding what to send; a URL this class cannot make sense of yields null, and the
 * request proceeds uninstrumented rather than failing.
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

        // A URL that carries its own authority keeps all of it and borrows at most the
        // scheme, which is what the scheme-relative "//host/path" form needs. Taking the
        // base's port too would put one host's port on another host's request.
        $authority = ($target['host'] ?? null) === null ? $base : $target;

        $scheme = self::lower($target['scheme'] ?? $base['scheme'] ?? '');
        $host = self::lower($authority['host'] ?? '');

        // The default port doubles as the scheme check: only the two schemes the HTTP
        // conventions describe have one, and anything else is not an HTTP request.
        $defaultPort = self::defaultPort($scheme);

        if ($host === '' || $defaultPort === null) {
            return null;
        }

        return new self(HttpMethod::fromString($method), $scheme, $host, $authority['port'] ?? $defaultPort);
    }

    /**
     * The frozen label set. Every one of these is bounded: the method is normalized to a
     * known name or `_OTHER`, and the rest come from the URL's authority.
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
     * The same, plus what only a span can afford: the method as the caller spelled it,
     * which is unbounded and so has no business being a metric label.
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
