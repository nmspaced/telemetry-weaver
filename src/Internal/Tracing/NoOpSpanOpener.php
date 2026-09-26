<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The span opener when the bundle is off: every span is an {@see InertSpan}. Tracing turned
 * off with an SDK present uses
 * {@see \Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\ContextOnlyOpener} instead.
 */
final readonly class NoOpSpanOpener implements SpanOpenerInterface
{
    private function __construct() {}

    public static function disabled(): self
    {
        return new self();
    }

    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): SpanOwner
    {
        return new InertSpan($name);
    }

    #[\Override]
    public function suppressed(): SpanOpenerInterface
    {
        return $this;
    }

    #[\Override]
    public function confining(): SpanOpenerInterface
    {
        return $this;
    }
}
