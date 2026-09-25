<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\ConfiguredBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\DurationRecorder;
use Nmspaced\TelemetryWeaver\Internal\Metrics\SafeMetrics;
use Nmspaced\TelemetryWeaver\Internal\Operation\DefaultTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Tracing\BaggageReader;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SignalSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener;
use Nmspaced\TelemetryWeaver\OpenTelemetry\SignalMeter;
use OpenTelemetry\API\Metrics\MeterInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the services every instrumented component owns: span opener, meter, metrics and
 * telemetry facades, and histogram boundaries.
 *
 * Both switches are resolved here, so nothing below needs to know whether a signal is on.
 *
 * @internal
 */
final readonly class InstrumentationServices
{
    /**
     * @param non-empty-string $component the configuration key, also the service id infix
     * @param DefaultBuckets|null $buckets the boundary preset; null when no duration is recorded
     */
    public static function register(
        ServicesConfigurator $services,
        string $component,
        ?DefaultBuckets $buckets = null,
    ): void {
        $services
            ->set(\sprintf('open_telemetry.%s.span_opener', $component), SpanOpenerInterface::class)
            ->factory(SignalSpanOpener::create(...))
            ->arg('$delegate', service(SpanOpener::class))
            ->arg('$tracesEnabled', param('open_telemetry.traces.enabled'))
            ->arg('$signalEnabled', param(\sprintf('open_telemetry.instrumentation.%s.traces', $component)));

        $services
            ->set(\sprintf('open_telemetry.%s.meter', $component), MeterInterface::class)
            ->factory(SignalMeter::create(...))
            ->arg('$delegate', service(MeterInterface::class))
            ->arg('$metricsEnabled', param('open_telemetry.metrics.enabled'))
            ->arg('$signalEnabled', param(\sprintf('open_telemetry.instrumentation.%s.metrics', $component)));

        $services
            ->set(\sprintf('open_telemetry.%s.metrics', $component), SafeMetrics::class)
            ->arg('$meter', service(\sprintf('open_telemetry.%s.meter', $component)))
            ->arg('$reporter', service(InstrumentationFailureReporter::class))
            ->arg('$recorder', service(DurationRecorder::class));

        $services
            ->set(\sprintf('open_telemetry.%s.telemetry', $component), DefaultTelemetry::class)
            ->arg('$opener', service(\sprintf('open_telemetry.%s.span_opener', $component)))
            ->arg('$instruments', service(\sprintf('open_telemetry.%s.metrics', $component)))
            ->arg('$reporter', service(InstrumentationFailureReporter::class))
            ->arg('$baggage', service(BaggageReader::class));

        if ($buckets === null) {
            return;
        }

        $services
            ->set(\sprintf('open_telemetry.%s.buckets', $component), OperationBuckets::class)
            ->factory(ConfiguredBuckets::orDefault(...))
            ->arg('$preset', $buckets)
            ->arg('$boundaries', param(\sprintf('open_telemetry.instrumentation.%s.duration_buckets', $component)));
    }
}
