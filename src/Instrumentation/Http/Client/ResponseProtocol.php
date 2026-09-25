<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

/**
 * `network.protocol.version` from the last status line the transport recorded, with `2.0`
 * and `3.0` folded into `2` and `3`. Nothing is reported without a status line.
 */
final readonly class ResponseProtocol
{
    private const string STATUS_LINE = '~^HTTP/(\d+(?:\.\d+)?) [1-9]\d\d(?: |$)~';

    /**
     * @param mixed $responseHeaders the `response_headers` info of a response
     *
     * @return non-empty-string|null
     */
    public static function of(mixed $responseHeaders): ?string
    {
        if (!\is_iterable($responseHeaders)) {
            return null;
        }

        $version = null;

        /** @var mixed $line */
        foreach ($responseHeaders as $line) {
            $matches = [];

            if (!\is_string($line) || \preg_match(self::STATUS_LINE, $line, $matches) !== 1) {
                continue;
            }

            /** @var array{non-empty-string, non-empty-string} $matches */
            $version = $matches[1];
        }

        return match ($version) {
            null => null,
            '2.0' => '2',
            '3.0' => '3',
            default => $version,
        };
    }
}
