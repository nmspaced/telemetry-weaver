<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Body sizes for the HTTP server histograms, without reading streamed bodies.
 *
 * Requests use `Content-Length` only; responses prefer it and fall back to buffered content.
 * An unknown size is null, not 0.
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

        if (!\is_string($content)) {
            return null;
        }

        return \strlen($content);
    }

    private static function fromHeader(?string $value): ?int
    {
        if ($value === null || !\ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
