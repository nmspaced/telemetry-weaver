<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

/**
 * Which remote hosts are left out, per signal.
 *
 * The collector is the reason this exists: when the OTLP exporter's transport is one of
 * the instrumented clients, every export is itself an outgoing request, and tracing it
 * produces spans that produce exports that produce spans. Excluding the collector's host
 * is what breaks the loop.
 *
 * Exact names and explicit `*.example.org` suffixes only — never a regular expression
 * from configuration. A pattern is matched against the host of every outgoing request in
 * the process, so a pathological pattern would be a denial of service on the application
 * itself rather than on whoever wrote it. `*.example.org` does not match `example.org`:
 * the wildcard stands for a label, so a suffix rule cannot silently swallow the apex.
 *
 * The two lists are independent because the two signals answer different questions. A
 * health probe against an internal service is noise in a trace and load in a metric, and
 * the useful configuration is usually to drop it from one and keep it in the other.
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
     * Case and the root's trailing dot are folded once, at construction, rather than on
     * every request: the lists are process-lifetime configuration and the comparison is
     * on the hot path of every outgoing call.
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
