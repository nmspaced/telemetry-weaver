<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Builds the logger provider with a batch processor that never exports from `emit()`; a no-op
 * provider when nothing receives records. Queues drain at execution boundaries.
 */
final readonly class LoggerProviderFactory
{
    public function __construct(
        private ResourceInfo $resourceInfo,
        private ?LogRecordExporterInterface $logRecordExporter,
    ) {}

    /**
     * @throws \InvalidArgumentException when the OTEL_BLRP_* sizes contradict each other
     */
    public function create(): LoggerProviderInterface
    {
        if ($this->logRecordExporter === null) {
            return new NoopLoggerProvider();
        }

        $processor = new BatchLogRecordProcessor(
            $this->logRecordExporter,
            Clock::getDefault(),
            Configuration::getInt(Variables::OTEL_BLRP_MAX_QUEUE_SIZE, Defaults::OTEL_BLRP_MAX_QUEUE_SIZE),
            Configuration::getInt(Variables::OTEL_BLRP_SCHEDULE_DELAY, Defaults::OTEL_BLRP_SCHEDULE_DELAY),
            Configuration::getInt(Variables::OTEL_BLRP_EXPORT_TIMEOUT, Defaults::OTEL_BLRP_EXPORT_TIMEOUT),
            Configuration::getInt(
                Variables::OTEL_BLRP_MAX_EXPORT_BATCH_SIZE,
                Defaults::OTEL_BLRP_MAX_EXPORT_BATCH_SIZE,
            ),
            autoFlush: false,
        );

        return LoggerProvider::builder()
            ->addLogRecordProcessor($processor)
            ->setResource($this->resourceInfo)
            ->build();
    }
}
