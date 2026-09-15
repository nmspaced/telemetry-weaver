<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

/**
 * `network.protocol.version` of a response, from the status line its transport recorded.
 *
 * Every Symfony transport — curl, native streams, Amp — writes the raw status line into
 * `response_headers`, and the client itself recognises it with this same shape. That is
 * transport metadata, not a guess: when there is no status line (a mock, a transport that
 * does not record one) nothing is reported, rather than the `1.1` most responses would
 * happen to be.
 *
 * The last status line wins. A redirect followed by the client, or an interim `1xx`,
 * leaves earlier lines in the list; the status this span completes with belongs to the
 * last. `2.0` and `3.0` are folded into `2` and `3`, the spelling the conventions use, so
 * one protocol cannot become two label values depending on who wrote the line.
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
