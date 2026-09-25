<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\ResponsePropagation;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;

/**
 * Builds response headers from the server span's context. Returns nothing unless
 * `OTEL_EXPERIMENTAL_RESPONSE_PROPAGATORS` configures a response propagator.
 *
 * @internal
 */
final readonly class OtelResponsePropagation implements ResponsePropagation
{
    public function __construct(
        private ResponsePropagatorInterface $propagator,
        private ContextStorageInterface $contextStorage,
        private InstrumentationFailureReporter $reporter,
    ) {}

    #[\Override]
    public function headers(): array
    {
        /** @var mixed $carrier */
        $carrier = [];

        try {
            // @mago-expect analysis:mixed-assignment — inject() takes the carrier as `mixed &`
            $this->propagator->inject($carrier, null, $this->contextStorage->current());
        } catch (\Throwable $throwable) {
            $this->reporter->report('Response propagation failed', 'http_server', $throwable);

            return [];
        }

        return \is_array($carrier) ? self::strings($carrier) : [];
    }

    /**
     * @param array<array-key, mixed> $carrier
     *
     * @return array<non-empty-string, string>
     */
    private static function strings(array $carrier): array
    {
        $headers = [];

        /** @var mixed $value */
        foreach ($carrier as $name => $value) {
            if (!\is_string($name) || $name === '' || !\is_string($value)) {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }
}
