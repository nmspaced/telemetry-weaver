<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Makes the container's providers the ones Globals hands out.
 *
 * Without this the package runs a pipeline nobody else can see: every
 * opentelemetry-auto-* instrumentation, and any application code that reaches
 * for Globals::tracerProvider() because that is what the OpenTelemetry docs
 * show, would write into a second, separately configured set of providers —
 * different resource, different exporter, different lifetime. One process,
 * two pipelines, and spans that never join up.
 *
 * Registration replaces rather than appends: Globals::reset() drops the memoised
 * instance and every initializer registered before, then the bundle's is the only
 * one. Folding after the SDK autoloader's initializer used to be enough to win, but
 * two things made appending wrong:
 *
 *  - Globals memoises on first read. A worker that clones its kernel after each
 *    request (FrankenPHP worker mode 2) boots a new container per request, and an
 *    appended initializer would never run again: Globals would keep handing out the
 *    first container's providers — sealed after that request's terminate, and holding
 *    the old container alive through the closures. GlobalsOwnershipTest checks that a
 *    new container both replaces the providers and lets the old one be collected.
 *  - With OTEL_PHP_AUTOLOAD_ENABLED on, the autoloader's initializer is not inert even
 *    when overridden. Folding runs it, so it built a complete second set of providers
 *    from OTEL_* variables and registered SDK shutdown callbacks for them — a pipeline that exported at PHP
 *    shutdown with no budget, outside the bundle's lifecycle.
 *
 * The cost is that anything else an earlier initializer configured is gone too — the
 * autoloader's response propagator, for one. Globals is the bundle's, and a second
 * writer into it was never supported; this makes that explicit instead of accidental.
 * Repeated register() calls of the same registrar are still a no-op, so booting the
 * same kernel twice does not throw away providers that are already in use.
 *
 * The providers arrive as closures so that reading Globals, not booting the
 * kernel, is what builds them — a request that emits no telemetry should not
 * pay for a TracerProvider.
 *
 * All three signals go through it, logs included. When logs export is off the
 * container's logger provider is the SDK's no-op anyway, so handing it over costs
 * nothing; when it is on, leaving it out would send every Logs API consumer to a
 * no-op while the bundle's own Monolog handler exports — two answers to "where do
 * logs go" in one process.
 *
 * On registerInitializer() and reset() being @internal
 * ----------------------------------------------------
 * They are, and knowingly so. The public alternative is the one
 * SdkBuilder::buildAndRegisterGlobal() uses — Configurator::storeInContext()
 * attached to the context storage — and it was measured and rejected:
 *
 *  - It is context scoped, with no process-level fallback. Unwind the stack
 *    and Globals silently returns noop. This package's contract is that
 *    telemetry fails loudly and harmlessly, never silently and wrongly.
 *  - It only resolves inside spans whose context descends from the attached
 *    one, so ParentContext would have to parent the main request span from a
 *    captured baseline instead of Context::getRoot(). That root is pristine by
 *    definition; a captured context is not, and anything that ever lands in it
 *    silently becomes the parent of every server span.
 *
 * The usual argument for the public route — "@todo says SPI will replace this"
 * — does not apply inside 1.x: SdkAutoloader::autoload() calls
 * registerInitializer() itself, so it cannot go away without breaking the SDK,
 * and composer pins open-telemetry/api to ^1.10; reset() sits next to it and is the
 * only way to drop a memoised instance. When 2.0 arrives this whole bridge gets revisited anyway, and GlobalsOwnershipTest
 * is what will say so.
 *
 * Until then the coupling is four lines, in one class, inside a try/catch in
 * the bundle's boot().
 */
final class GlobalsRegistrar
{
    /**
     * The public alias the bundle's boot() resolves. The class itself stays a
     * private service — this is the only id of it the container exposes.
     */
    public const string REGISTRAR_ID = 'open_telemetry.globals_registrar';

    private bool $registered = false;

    /**
     * @param \Closure(): TracerProviderInterface $tracerProvider
     * @param \Closure(): MeterProviderInterface $meterProvider
     * @param \Closure(): LoggerProviderInterface $loggerProvider
     * @param \Closure(): TextMapPropagatorInterface $propagator
     */
    public function __construct(
        private readonly \Closure $tracerProvider,
        private readonly \Closure $meterProvider,
        private readonly \Closure $loggerProvider,
        private readonly \Closure $propagator,
    ) {}

    /**
     * Idempotent: the kernel can boot more than once in a worker, and a second
     * initializer would only be asked for the same providers again.
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

        Globals::reset();
        Globals::registerInitializer(static fn(Configurator $configurator): Configurator => $configurator
            ->withTracerProvider($tracerProvider())
            ->withMeterProvider($meterProvider())
            ->withLoggerProvider($loggerProvider())
            ->withPropagator($propagator()));
    }
}
