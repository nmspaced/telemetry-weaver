<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\ResponsePropagation;
use OpenTelemetry\Context\ContextStorageInterface;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;

/**
 * Builds the response headers from the context the server span is running in.
 *
 * The context comes from the injected storage rather than `Context::getCurrent()`, as
 * everywhere else in the adapter, and it is read at call time on purpose: the caller is
 * the response listener, which runs while the server span is still the current one.
 *
 * What actually ends up in the headers is not this class's decision. The SDK resolves
 * `OTEL_EXPERIMENTAL_RESPONSE_PROPAGATORS` against its registry, and the registry ships
 * only `none` — `traceresponse` itself is a separate contrib package an application
 * installs. With none configured this returns an empty array on every response, which is
 * the point: the seam costs nothing until somebody wants it, and without it the
 * environment variable would have nowhere to take effect.
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
