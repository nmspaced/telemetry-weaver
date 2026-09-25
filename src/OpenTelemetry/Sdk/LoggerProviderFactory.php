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
 * provider when nothing receives records. Queues drain at execution boundaries, early once
 * {@see ExportBacklog} holds a full batch.
 */
final readonly class LoggerProviderFactory
{
    public function __construct(
        private ResourceInfo $resourceInfo,
        private ?LogRecordExporterInterface $logRecordExporter,
    ) {}

    /**
     * @param ExportBacklog|null $backlog shared with the boundary flush; null sizes it from OTEL_BLRP_*
     *
     * @throws \InvalidArgumentException when the OTEL_BLRP_* sizes contradict each other
     */
    public function create(?ExportBacklog $backlog = null): LoggerProviderInterface
    {
        if ($this->logRecordExporter === null) {
            return new NoopLoggerProvider();
        }

        $backlog ??= ExportBacklog::logRecords();

        $processor = new BacklogLogRecordProcessor(
            new BatchLogRecordProcessor(
                $this->logRecordExporter,
                Clock::getDefault(),
                $backlog->capacity,
                Configuration::getInt(Variables::OTEL_BLRP_SCHEDULE_DELAY, Defaults::OTEL_BLRP_SCHEDULE_DELAY),
                Configuration::getInt(Variables::OTEL_BLRP_EXPORT_TIMEOUT, Defaults::OTEL_BLRP_EXPORT_TIMEOUT),
                $backlog->batchSize,
                autoFlush: false,
            ),
            $backlog,
        );

        return LoggerProvider::builder()
            ->addLogRecordProcessor($processor)
            ->setResource($this->resourceInfo)
            ->build();
    }
}
