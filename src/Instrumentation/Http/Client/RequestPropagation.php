<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Writes the current trace context into the request's headers.
 *
 * The propagator's own fields are replaced rather than added to: two `traceparent`
 * headers are not a valid request, and whichever the server picked would be a coin toss.
 * Everything else the caller passed survives — authorization, content type, the
 * application's own correlation headers — because this decorator has no business
 * editing a request beyond its own concern, and nothing about the body is touched.
 *
 * Headers reach Symfony in two shapes: a map of name to value, and a list of raw
 * `Name: value` strings. Both are accepted, and a header is removed by whichever of the
 * two spellings it arrived in.
 *
 * Injection happens even for a request whose span is excluded. An excluded host is a
 * statement about what this process records, not about what the next service is allowed
 * to know: dropping the header there would break the trace at the boundary rather than
 * at the exclusion.
 */
final readonly class RequestPropagation
{
    public function __construct(
        private TextMapPropagatorInterface $propagator,
        private InstrumentationFailureReporter $reporter,
    ) {}

    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<array-key, mixed>
     */
    public function inject(array $options): array
    {
        try {
            $carrier = $this->carrier();

            if ($carrier === []) {
                return $options;
            }

            /** @var mixed $headers */
            $headers = $options['headers'] ?? [];

            if (!\is_array($headers)) {
                return $options;
            }

            $options['headers'] = $carrier + self::without($headers, $this->propagator->fields());

            return $options;
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP context injection failed', 'http_client', $throwable);

            return $options;
        }
    }

    /**
     * @return array<string, string>
     */
    private function carrier(): array
    {
        /** @var mixed $carrier */
        $carrier = [];
        // @mago-expect analysis:mixed-assignment — TextMapPropagatorInterface::inject() takes the carrier as `mixed &`
        $this->propagator->inject($carrier);

        if (!\is_array($carrier)) {
            return [];
        }

        $injected = [];

        /** @var mixed $value */
        foreach ($carrier as $name => $value) {
            if (!(\is_string($name) && \is_string($value))) {
                continue;
            }

            $injected[$name] = $value;
        }

        return $injected;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @param list<string> $fields
     *
     * @return array<array-key, mixed>
     */
    private static function without(array $headers, array $fields): array
    {
        $fields = \array_map(\strtolower(...), $fields);
        $kept = [];

        /** @var mixed $value */
        foreach ($headers as $key => $value) {
            if (\in_array(self::nameOf($key, $value), $fields, true)) {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }

    /**
     * The header's name, folded for comparison — the map key when there is one, and
     * otherwise whatever precedes the colon of a raw header line.
     */
    private static function nameOf(string|int $key, mixed $value): string
    {
        if (\is_string($key)) {
            return \strtolower(\trim($key));
        }

        return \is_string($value) ? \strtolower(\trim(\explode(':', $value, 2)[0])) : '';
    }
}
