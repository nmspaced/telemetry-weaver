<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Releases counted records when the SDK releases its batch, even if export fails.
 *
 * @internal
 */
final readonly class BacklogSpanExporter implements SpanExporterInterface
{
    public function __construct(
        private SpanExporterInterface $delegate,
        private ExportBacklog $backlog,
    ) {}

    /**
     * @param iterable<SpanDataInterface> $batch
     * @return FutureInterface<bool>
     */
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        $records = \is_array($batch) ? $batch : \iterator_to_array($batch, false);

        try {
            // The batch processor immediately awaits this result. Keep in-flight records
            // counted until await finishes, matching the SDK's capacity accounting.
            return new CompletedFuture($this->delegate->export($records, $cancellation)->await());
        } finally {
            $this->backlog->completed(\count($records));
        }
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->delegate->forceFlush($cancellation);
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->delegate->shutdown($cancellation);
    }
}
