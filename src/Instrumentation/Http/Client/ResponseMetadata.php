<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\QueryStringRedactor;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Records `url.full` via `getInfo()`, which keeps the response lazy, before any redirect.
 * Credentials and fragment are dropped and query values redacted; never a metric label.
 */
final readonly class ResponseMetadata
{
    public function __construct(
        private InstrumentationFailureReporter $reporter,
    ) {}

    public function describe(RunningOperation $operation, ResponseInterface $response): void
    {
        try {
            /** @var mixed $url */
            $url = $response->getInfo('url');
            $safe = \is_string($url) ? self::sanitize($url) : null;

            if ($safe !== null) {
                $operation->span()->attribute(UrlAttributes::URL_FULL, $safe);
            }
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP URL extraction failed', 'http_client', $throwable);
        }
    }

    /**
     * @return string|null null when the URL lacks a scheme or host
     */
    private static function sanitize(string $url): ?string
    {
        $parts = \parse_url($url);

        if ($parts === false) {
            return null;
        }

        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? null;

        if ($host === null || $scheme === null) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $query = $parts['query'] ?? null;

        $safe = \strtolower($scheme) . '://' . \strtolower($host);
        $safe .= $port === null ? '' : ':' . $port;
        $safe .= $parts['path'] ?? '/';

        return $query === null ? $safe : $safe . '?' . QueryStringRedactor::redact($query);
    }
}
