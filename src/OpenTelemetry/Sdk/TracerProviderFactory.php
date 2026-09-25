<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\KnownValues;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SamplerFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorFactory;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\SpanSuppression\SpanSuppressionStrategy;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

/**
 * Builds the tracer provider with a batch processor that never exports from `span->end()`;
 * queues drain at execution boundaries instead.
 *
 * Configured sampler, id generator and span processors are added to this pipeline; extra
 * processors run before the batch processor.
 */
final readonly class TracerProviderFactory
{
    public function __construct(
        private ResourceInfo $resourceInfo,
        private MeterProviderInterface $meterProvider,
        private ?SpanExporterInterface $spanExporter,
        private SpanSuppressionStrategy $spanSuppressionStrategy,
        private TraceDecisions $decisions = new TraceDecisions(),
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function create(): TracerProviderInterface
    {
        $internalMetricsEnabled = Configuration::getBoolean(Variables::OTEL_PHP_INTERNAL_METRICS_ENABLED);
        $internalMeterProvider = $internalMetricsEnabled ? $this->meterProvider : null;

        $processors = \iterator_to_array($this->decisions->spanProcessors, false);
        $processors[] = $this->spanProcessor($internalMeterProvider);

        return new TracerProvider(
            spanProcessors: $processors,
            sampler: $this->decisions->sampler ?? new SamplerFactory()->create(),
            resource: $this->resourceInfo,
            idGenerator: $this->decisions->idGenerator,
            spanSuppressionStrategy: $this->spanSuppressionStrategy,
            meterProvider: $internalMeterProvider,
        );
    }

    /**
     * @throws \RuntimeException
     */
    private function spanProcessor(?MeterProviderInterface $internalMeterProvider): SpanProcessorInterface
    {
        if (
            $this->spanExporter === null
            || Configuration::getEnum(Variables::OTEL_PHP_TRACES_PROCESSOR) !== KnownValues::VALUE_BATCH
        ) {
            return new SpanProcessorFactory()->create($this->spanExporter, $internalMeterProvider);
        }

        return new BatchSpanProcessor(
            $this->spanExporter,
            Clock::getDefault(),
            Configuration::getInt(Variables::OTEL_BSP_MAX_QUEUE_SIZE, BatchSpanProcessor::DEFAULT_MAX_QUEUE_SIZE),
            Configuration::getInt(Variables::OTEL_BSP_SCHEDULE_DELAY, BatchSpanProcessor::DEFAULT_SCHEDULE_DELAY),
            Configuration::getInt(Variables::OTEL_BSP_EXPORT_TIMEOUT, BatchSpanProcessor::DEFAULT_EXPORT_TIMEOUT),
            Configuration::getInt(
                Variables::OTEL_BSP_MAX_EXPORT_BATCH_SIZE,
                BatchSpanProcessor::DEFAULT_MAX_EXPORT_BATCH_SIZE,
            ),
            autoFlush: false,
            meterProvider: $internalMeterProvider ?? new NoopMeterProvider(),
        );
    }
}
