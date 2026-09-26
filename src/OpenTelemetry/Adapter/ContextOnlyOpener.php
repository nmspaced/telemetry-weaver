<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * Opens operations that record no span but still run in their own context: with tracing
 * off, or when `only_with_parent` suppresses the span.
 *
 * The operation still continues the incoming trace, carries its baggage and restores the
 * previous context. A context is activated only when it differs from the ambient one, or
 * when the opener confines what runs inside its operations.
 *
 * @internal
 */
final readonly class ContextOnlyOpener implements SpanOpenerInterface
{
    public function __construct(
        private ContextStorageInterface $contextStorage,
        private InstrumentationFailureReporter $reporter,
        private bool $confining = false,
    ) {}

    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): OwnedSpan
    {
        $ambient = $this->contextStorage->current();
        $context = OperationParent::resolve($options, $ambient);

        if ($context === $ambient && !$this->confining) {
            return OwnedSpan::inert($name, new OtelTraceCorrelation($ambient));
        }

        try {
            $activation = $this->contextStorage->attach($context);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context activation failed', $name, $throwable);

            return OwnedSpan::inert($name, new OtelTraceCorrelation($context));
        }

        $owner = OwnedSpan::activated(
            $name,
            Span::getInvalid(),
            $activation,
            $this->reporter,
            new OtelTraceCorrelation($context),
        )->reenterableIn($this->contextStorage, $context);

        return $this->confining ? $owner->confining($this->contextStorage) : $owner;
    }

    #[\Override]
    public function suppressed(): self
    {
        return $this;
    }

    #[\Override]
    public function confining(): self
    {
        return new self($this->contextStorage, $this->reporter, true);
    }
}
