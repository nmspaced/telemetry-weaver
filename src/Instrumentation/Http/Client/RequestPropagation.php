<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;

/**
 * Writes the current trace context into a request's headers, also for excluded hosts.
 *
 * The propagator's fields replace any the caller set; other headers are kept. Both header
 * shapes Symfony accepts (a name => value map and raw `Name: value` lines) are handled.
 */
final readonly class RequestPropagation
{
    public function __construct(
        private Propagation $propagation,
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
            $carrier = $this->propagation->injectCurrent();

            if ($carrier === []) {
                return $options;
            }

            /** @var mixed $headers */
            $headers = $options['headers'] ?? [];

            if (!\is_array($headers)) {
                return $options;
            }

            $options['headers'] = $carrier + self::without($headers, $this->propagation->fields());

            return $options;
        } catch (\Throwable $throwable) {
            $this->reporter->report('HTTP context injection failed', 'http_client', $throwable);

            return $options;
        }
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

    /** The lower-cased header name, from the map key or the raw `Name: value` line. */
    private static function nameOf(string|int $key, mixed $value): string
    {
        if (\is_string($key)) {
            return \strtolower(\trim($key));
        }

        return \is_string($value) ? \strtolower(\trim(\explode(':', $value, 2)[0])) : '';
    }
}
