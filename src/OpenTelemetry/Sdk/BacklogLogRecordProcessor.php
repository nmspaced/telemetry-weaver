<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;

/**
 * Counts what the batch log record processor queues: every emitted record.
 *
 * @internal
 */
final readonly class BacklogLogRecordProcessor implements LogRecordProcessorInterface
{
    public function __construct(
        private LogRecordProcessorInterface $delegate,
        private ExportBacklog $backlog,
    ) {}

    #[\Override]
    public function onEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void
    {
        $this->backlog->added();
        $this->delegate->onEmit($record, $context);
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->delegate->forceFlush($cancellation);
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        $this->backlog->close();

        return $this->delegate->shutdown($cancellation);
    }
}
