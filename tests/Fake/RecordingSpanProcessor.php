<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;

/** Records every span and stamps an attribute on start. */
final class RecordingSpanProcessor implements SpanProcessorInterface
{
    /** @var list<string> */
    public array $started = [];

    /** @var list<string> */
    public array $ended = [];

    /**
     * @param non-empty-string $attribute
     */
    public function __construct(
        private readonly string $attribute = 'processed.by',
    ) {}

    #[\Override]
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        $this->started[] = $span->getName();
        $span->setAttribute($this->attribute, self::class);
    }

    #[\Override]
    public function onEnd(ReadableSpanInterface $span): void
    {
        $this->ended[] = $span->getName();
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
