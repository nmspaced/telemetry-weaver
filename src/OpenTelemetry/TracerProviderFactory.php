<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

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
 * The tracer provider, with a batch processor that never exports from `span->end()`.
 *
 * The SDK's `SpanProcessorFactory` hard-codes `autoFlush: true`, and with it `onEnd()`
 * exports — and awaits the export — whenever a batch fills *or* `OTEL_BSP_SCHEDULE_DELAY`
 * has elapsed since the first queued span. Under steady traffic that is every five
 * seconds, inside whatever request happens to end the span, blocked for up to the OTLP
 * timeout when the collector is down. `Resilient*Exporter` cannot help with that: it
 * catches throws, not waiting.
 *
 * So the batch processor is built here with `autoFlush` off. Ending a span only enqueues
 * it; `TelemetryFlusher` drains the queue at the execution boundary, on the same schedule
 * delay, after the response is sent. A queue that fills before the next boundary drops
 * spans — `OTEL_BSP_MAX_QUEUE_SIZE` is the bound on both memory and loss, and losing
 * spans is the correct outcome where the alternative is blocking the application.
 *
 * Every other processor choice (`simple`, `none`) is still left to the SDK factory:
 * `simple` exports on every span by definition, and whoever sets it has asked for that.
 */
final readonly class TracerProviderFactory
{
    public function __construct(
        private ResourceInfo $resourceInfo,
        private MeterProviderInterface $meterProvider,
        private ?SpanExporterInterface $spanExporter,
        private SpanSuppressionStrategy $spanSuppressionStrategy,
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function create(): TracerProviderInterface
    {
        $internalMetricsEnabled = Configuration::getBoolean(Variables::OTEL_PHP_INTERNAL_METRICS_ENABLED);
        $internalMeterProvider = $internalMetricsEnabled ? $this->meterProvider : null;

        $builder = TracerProvider::builder()
            ->setResource($this->resourceInfo)
            ->setSampler(new SamplerFactory()->create())
            ->addSpanProcessor($this->spanProcessor($internalMeterProvider))
            ->setSpanSuppressionStrategy($this->spanSuppressionStrategy);

        if ($internalMeterProvider !== null) {
            $builder->setMeterProvider($internalMeterProvider);
        }

        return $builder->build();
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
