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
 * Builds the meter provider with the application's registered views, which
 * `MeterProviderBuilder` cannot accept.
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
