<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

/**
 * The OTLP transport settings that have no OTEL_* variable behind them, carried as one value.
 *
 * Retrying is not asynchronous: `PsrTransport::send()` sleeps in the calling process between
 * attempts (`time_nanosleep`), so a collector that is down costs up to `maxRetries + 1` export
 * timeouts plus the backoff — tens of seconds inside a request. That is why nothing is retried
 * unless configured.
 *
 * Headers are merged over OTEL_EXPORTER_OTLP_HEADERS. The upstream exporter factory resolves
 * that variable and hands it to the transport factory as `$headers`, and there is no other seam
 * between it and the wire, so `sdk.exporter_otlp_headers` is applied there: the configured
 * entries win, which is what makes a header settable per environment in a config file.
 */
final readonly class OtlpTransportSettings
{
    /**
     * @param int<0, max> $maxRetries 0 disables retrying entirely
     * @param int<0, max> $retryDelay base backoff in milliseconds, doubled per attempt
     * @param array<non-empty-string, bool|float|int|string|null> $headers merged over OTEL_EXPORTER_OTLP_HEADERS
     */
    public function __construct(
        public int $maxRetries = 0,
        public int $retryDelay = 100,
        private array $headers = [],
    ) {}

    /**
     * A null value removes a header the variable set — that is how a config file switches
     * one off without having to rewrite the whole variable. Everything else is stringified,
     * because the tree accepts any scalar and a header value is text on the wire.
     *
     * @param array<string, array<array-key, string>|string> $headers
     *
     * @return array<string, array<array-key, string>|string>
     */
    public function headers(array $headers): array
    {
        foreach ($this->headers as $name => $value) {
            if ($value === null) {
                unset($headers[$name]);

                continue;
            }

            $headers[$name] = self::text($value);
        }

        return $headers;
    }

    private static function text(bool|float|int|string $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
