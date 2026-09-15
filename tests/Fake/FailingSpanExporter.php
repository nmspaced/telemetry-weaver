<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * Span exporter that fails in one of two forms.
 *
 * Synchronous: an exception straight out of export() — a transport failing
 * DNS resolution. Deferred: a rejected Future — everything else.
 */
final readonly class FailingSpanExporter implements SpanExporterInterface
{
    private function __construct(
        private \Throwable $failure,
        private bool $throwsSynchronously,
    ) {}

    public static function throwing(\Throwable $failure): self
    {
        return new self($failure, true);
    }

    public static function rejecting(\Throwable $failure): self
    {
        return new self($failure, false);
    }

    /**
     * @throws \Throwable when created via throwing()
     */
    #[\Override]
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->throwsSynchronously) {
            throw $this->failure;
        }

        return new ErrorFuture($this->failure);
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
