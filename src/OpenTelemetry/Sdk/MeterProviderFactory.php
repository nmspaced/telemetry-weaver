<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\KnownValues;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\AllExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\NoneExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\WithSampledTraceExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilterInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Metrics\StalenessHandler\NoopStalenessHandlerFactory;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * The meter provider, with whatever views the application registered.
 *
 * Views are the specification's mechanism for changing what an instrument produces without
 * changing the code that writes it — a different aggregation, a narrower set of attributes, or
 * a metric dropped entirely. The bundle needed a way to pass them through because
 * `MeterProviderBuilder` has none: it constructs an empty `CriteriaViewRegistry` and offers no
 * way to register into it, so the provider is constructed here instead.
 *
 * `instrumentation.<component>.duration_buckets` covers the common case — the boundaries of an
 * instrument the bundle created — without any of this. Views are for the rest: an instrument
 * the application or a third-party library created, and attribute keys whose cardinality has
 * to be cut at the source.
 */
final readonly class MeterProviderFactory
{
    /**
     * @param iterable<MetricView> $views applied in order; several may match one instrument
     */
    public function __construct(
        private ResourceInfo $resourceInfo,
        private ?MetricExporterInterface $metricExporter,
        private iterable $views = [],
    ) {}

    public function create(): MeterProviderInterface
    {
        if ($this->metricExporter === null) {
            return new NoopMeterProvider();
        }

        $exemplarFilter = $this->createExemplarFilter(Configuration::getEnum(Variables::OTEL_METRICS_EXEMPLAR_FILTER));

        $registry = new CriteriaViewRegistry();
        foreach ($this->views as $view) {
            $registry->register($view->criteria, $view->template);
        }

        $attributes = Attributes::factory();

        return new MeterProvider(
            contextStorage: null,
            resource: $this->resourceInfo,
            clock: Clock::getDefault(),
            attributesFactory: $attributes,
            instrumentationScopeFactory: new InstrumentationScopeFactory($attributes),
            metricReaders: [new ExportingReader($this->metricExporter)],
            viewRegistry: $registry,
            exemplarFilter: $exemplarFilter,
            stalenessHandlerFactory: new NoopStalenessHandlerFactory(),
        );
    }

    private function createExemplarFilter(string $name): ExemplarFilterInterface
    {
        return match ($name) {
            KnownValues::VALUE_WITH_SAMPLED_TRACE => new WithSampledTraceExemplarFilter(),
            KnownValues::VALUE_ALL => new AllExemplarFilter(),
            default => new NoneExemplarFilter(),
        };
    }
}
