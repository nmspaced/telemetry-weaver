<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Body sizes for the HTTP server size histograms.
 *
 * Content-Length is the only source consulted for a request: reading the body
 * to measure it would consume a stream the application still has to read.
 * For a response the header is preferred for the same reason, and the buffered
 * content is measured only when there is a real string to measure — a streamed
 * or file-backed response answers getContent() with false, and forcing one to
 * materialise would be a telemetry feature that changes what the app sends.
 *
 * An unknown size is null, never 0: a zero recorded into a histogram is a
 * measurement, and "we could not tell" is not one.
 */
final readonly class HttpBodySize
{
    public static function ofRequest(Request $request): ?int
    {
        return self::fromHeader($request->headers->get('Content-Length'));
    }

    public static function ofResponse(Response $response): ?int
    {
        $declared = self::fromHeader($response->headers->get('Content-Length'));

        if ($declared !== null) {
            return $declared;
        }

        $content = $response->getContent();

        return \is_string($content) ? \strlen($content) : null;
    }

    private static function fromHeader(?string $value): ?int
    {
        if ($value === null || !\ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
