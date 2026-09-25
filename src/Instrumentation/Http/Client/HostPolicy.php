<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

/**
 * Hosts excluded from traces and from metrics, independently — above all the collector,
 * whose exports would otherwise instrument themselves.
 *
 * Exact names or `*.example.org` suffixes only, never regular expressions; `*.example.org`
 * does not match `example.org`.
 */
final readonly class HostPolicy
{
    /** @var list<string> */
    private array $tracingExclusions;

    /** @var list<string> */
    private array $metricExclusions;

    /**
     * @param list<string> $excludedTraceHosts
     * @param list<string> $excludedMetricHosts
     */
    public function __construct(array $excludedTraceHosts = [], array $excludedMetricHosts = [])
    {
        $this->tracingExclusions = self::normalize($excludedTraceHosts);
        $this->metricExclusions = self::normalize($excludedMetricHosts);
    }

    public function trace(string $host): bool
    {
        return !self::excluded($host, $this->tracingExclusions);
    }

    public function measure(string $host): bool
    {
        return !self::excluded($host, $this->metricExclusions);
    }

    /**
     * Folds case and the trailing dot once, at construction.
     *
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    private static function normalize(array $hosts): array
    {
        return \array_map(self::fold(...), $hosts);
    }

    /** @param list<string> $patterns */
    private static function excluded(string $host, array $patterns): bool
    {
        $host = self::fold($host);

        return \array_any($patterns, static fn(string $pattern): bool => self::matches($host, $pattern));
    }

    private static function matches(string $host, string $pattern): bool
    {
        if (\str_starts_with($pattern, '*.')) {
            return \str_ends_with($host, \substr($pattern, 1));
        }

        return $host === $pattern;
    }

    private static function fold(string $host): string
    {
        return \strtolower(\rtrim($host, '.'));
    }
}
