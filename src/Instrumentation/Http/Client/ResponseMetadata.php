<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\QueryStringRedactor;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Records `url.full` from the response, without making the response happen.
 *
 * `getInfo()` is the one part of a Symfony response that does not initialize it, so
 * reading the URL here keeps the client lazy: nothing is sent, nothing is waited for.
 * It is read immediately, before any redirect, because the attribute is supposed to
 * describe the request this span is about and a redirect chain would silently replace it
 * with a different one.
 *
 * What reaches the attribute is rebuilt from the parts rather than passed through.
 * Credentials and the fragment are dropped — the first is a secret and the second never
 * leaves the client — and every query value is redacted while the parameter names stay,
 * which is what makes the attribute worth having. The URL is deliberately absent from
 * the metric labels: a path is unbounded, and one timeseries per distinct URL is how a
 * metrics backend is brought down.
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
     * @return string|null null when the parts do not add up to a URL worth reporting;
     *                     a half-built one would be worse than none
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
