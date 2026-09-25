<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\ResponsePropagatorInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Makes the container's providers the ones `Globals` hands out, so auto-instrumentation
 * and application code share one pipeline.
 *
 * It replaces earlier initializers instead of appending: `Globals` memoises the first read, and
 * the SDK autoloader would otherwise build a second pipeline. `registerInitializer()` and
 * `reset()` are `@internal` in the SDK but are the only process-wide option in 1.x.
 */
final class GlobalsRegistrar
{
    /**
     * The public alias `boot()` resolves; the class itself stays private.
     */
    public const string REGISTRAR_ID = 'open_telemetry.globals_registrar';

    private bool $registered = false;

    /**
     * @param \Closure(): TracerProviderInterface $tracerProvider
     * @param \Closure(): MeterProviderInterface $meterProvider
     * @param \Closure(): LoggerProviderInterface $loggerProvider
     * @param \Closure(): TextMapPropagatorInterface $propagator
     * @param \Closure(): ResponsePropagatorInterface $responsePropagator
     */
    public function __construct(
        private readonly \Closure $tracerProvider,
        private readonly \Closure $meterProvider,
        private readonly \Closure $loggerProvider,
        private readonly \Closure $propagator,
        private readonly \Closure $responsePropagator,
    ) {}

    /**
     * Idempotent, because a worker may boot the kernel more than once.
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $tracerProvider = $this->tracerProvider;
        $meterProvider = $this->meterProvider;
        $loggerProvider = $this->loggerProvider;
        $propagator = $this->propagator;
        $responsePropagator = $this->responsePropagator;

        Globals::reset();
        Globals::registerInitializer(static fn(Configurator $configurator): Configurator => $configurator
            ->withTracerProvider($tracerProvider())
            ->withMeterProvider($meterProvider())
            ->withLoggerProvider($loggerProvider())
            ->withPropagator($propagator())
            ->withResponsePropagator($responsePropagator()));
    }
}
