<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\KnownValues;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\AllExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\NoneExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\WithSampledTraceExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilterInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;

final readonly class MeterProviderFactory
{
    public function __construct(
        private ResourceInfo $resourceInfo,
        private ?MetricExporterInterface $metricExporter,
    ) {}

    public function create(): MeterProviderInterface
    {
        if ($this->metricExporter === null) {
            return new NoopMeterProvider();
        }

        $exemplarFilter = $this->createExemplarFilter(Configuration::getEnum(Variables::OTEL_METRICS_EXEMPLAR_FILTER));

        $builder = MeterProvider::builder()->setResource($this->resourceInfo)->setExemplarFilter($exemplarFilter);

        $builder->addReader(new ExportingReader($this->metricExporter));

        return $builder->build();
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
