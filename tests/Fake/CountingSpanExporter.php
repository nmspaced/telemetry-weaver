<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/** Counts exported spans instead of keeping them, so long runs do not look like a leak. */
final class CountingSpanExporter implements SpanExporterInterface
{
    public int $exported = 0;

    /** @var array<string, int> */
    public array $names = [];

    /** @var list<string> trace ids of spans that arrived with a parent */
    public array $parentedTraceIds = [];

    public ?\Throwable $failOn = null;

    public int $failAtCount = -1;

    /**
     * @param iterable<SpanDataInterface> $batch
     *
     * @throws \Throwable when configured to fail
     */
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        foreach ($batch as $span) {
            ++$this->exported;
            $this->names[$span->getName()] = ($this->names[$span->getName()] ?? 0) + 1;

            if ($span->getParentContext()->isValid()) {
                $this->parentedTraceIds[] = $span->getContext()->getTraceId();
            }

            if ($this->failOn !== null && $this->exported === $this->failAtCount) {
                throw $this->failOn;
            }
        }

        return new CompletedFuture(true);
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
