<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

/**
 * OTLP transport settings that have no `OTEL_*` variable. Retries are off by default because
 * the SDK retries by sleeping in the calling process.
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
     * Merges configured headers over `OTEL_EXPORTER_OTLP_HEADERS`; a null value removes one.
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
