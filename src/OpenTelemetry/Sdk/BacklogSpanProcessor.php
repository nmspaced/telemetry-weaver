<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/**
 * Counts what the batch span processor queues, the same spans it accepts: sampled ones.
 *
 * @internal
 */
final readonly class BacklogSpanProcessor implements SpanProcessorInterface
{
    public function __construct(
        private SpanProcessorInterface $delegate,
        private ExportBacklog $backlog,
    ) {}

    #[\Override]
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->delegate->onStart($span, $parentContext);
    }

    #[\Override]
    public function onEnd(ReadableSpanInterface $span): void
    {
        if ($span->getContext()->isSampled()) {
            $this->backlog->added();
        }

        $this->delegate->onEnd($span);
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->delegate->forceFlush($cancellation);
        } finally {
            $this->backlog->drained();
        }
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        try {
            return $this->delegate->shutdown($cancellation);
        } finally {
            $this->backlog->drained();
        }
    }
}
