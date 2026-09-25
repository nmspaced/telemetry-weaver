<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/** Delegates, but throws from `onEnd()` for a chosen span. */
final readonly class FlakySpanProcessor implements SpanProcessorInterface
{
    public function __construct(
        private SpanProcessorInterface $inner,
        private string $failingSpanName,
    ) {}

    #[\Override]
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->inner->onStart($span, $parentContext);
    }

    /** @throws \RuntimeException for the chosen span */
    #[\Override]
    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->inner->onEnd($span);

        if ($span->getName() === $this->failingSpanName) {
            throw new \RuntimeException('processing this span failed');
        }
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->forceFlush($cancellation);
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->inner->shutdown($cancellation);
    }
}
