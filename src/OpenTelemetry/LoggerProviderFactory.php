<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

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
 * The logger provider, or a no-op one when nothing is configured to receive records.
 *
 * Batched rather than simple, for the same reason spans are: a simple processor exports
 * inside `$logger->error()`, which puts a network round trip — and a dead collector's
 * timeout — on the path of the code that was trying to report a problem. Batching moves
 * that cost to `TelemetryFlusher`, which runs at an execution boundary after the response has
 * been sent.
 *
 * `autoFlush` is off, as for spans (see `TracerProviderFactory`): with it on, `onEmit()`
 * exports whenever a batch fills or `OTEL_BLRP_SCHEDULE_DELAY` — one second by default —
 * has elapsed, which under traffic is nearly every request, and a burst of error logs is
 * exactly when the collector is most likely to be struggling. A queue that fills before
 * the boundary drops records; `OTEL_BLRP_MAX_QUEUE_SIZE` bounds both memory and loss.
 *
 * The `OTEL_BLRP_*` sizes are read here because this factory builds the processor itself
 * instead of going through the SDK's, which would have forced `autoFlush` back on.
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
