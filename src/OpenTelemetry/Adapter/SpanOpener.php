<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceRelations;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;

/**
 * Starts and activates an SDK span for an operation: chooses its parent, links and kind.
 */
final readonly class SpanOpener implements SpanOpenerInterface
{
    public function __construct(
        private TracerInterface $tracer,
        private ContextStorageInterface $contextStorage,
        private InstrumentationFailureReporter $instrumentationFailureReporter,
        private bool $confining = false,
    ) {}

    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): OwnedSpan
    {
        $ambient = $this->contextStorage->current();

        if ($options->onlyInsideTrace && !Span::fromContext($ambient)->getContext()->isValid()) {
            return $this->suppressed()->open($name, $options);
        }

        $parent = OperationParent::resolve($options, $ambient);

        try {
            $builder = $this->tracer
                ->spanBuilder($name)
                ->setSpanKind(self::kind($options->kind))
                ->setAttributes($options->attributes)
                ->setParent($parent);

            foreach (self::linkedContexts($options->relations, $ambient) as $link) {
                $builder->addLink($link);
            }

            $span = $builder->startSpan();
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter->report('Span creation failed', $name, $throwable);

            return $this->suppressed()->open($name, $options);
        }

        return $this->activate($name, $span, $parent);
    }

    /**
     * An opener that records no span but keeps the same context handling.
     */
    #[\Override]
    public function suppressed(): ContextOnlyOpener
    {
        return new ContextOnlyOpener($this->contextStorage, $this->instrumentationFailureReporter, $this->confining);
    }

    #[\Override]
    public function confining(): self
    {
        return new self($this->tracer, $this->contextStorage, $this->instrumentationFailureReporter, true);
    }

    /**
     * @return list<SpanContextInterface>
     */
    private static function linkedContexts(TraceRelations $relations, ContextInterface $ambient): array
    {
        $links = [];

        foreach ($relations->links as $link) {
            if (!$link instanceof OtelIncomingTrace) {
                continue;
            }

            $context = Span::fromContext($link->context)->getContext();

            if ($context->isValid()) {
                $links[] = $context;
            }
        }

        if ($relations->linkActiveSpan) {
            $active = Span::fromContext($ambient)->getContext();

            if ($active->isValid()) {
                $links[] = $active;
            }
        }

        return $links;
    }

    /**
     * @return int<0, 4>
     */
    private static function kind(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::Internal => OtelSpanKind::KIND_INTERNAL,
            SpanKind::Server => OtelSpanKind::KIND_SERVER,
            SpanKind::Client => OtelSpanKind::KIND_CLIENT,
            SpanKind::Producer => OtelSpanKind::KIND_PRODUCER,
            SpanKind::Consumer => OtelSpanKind::KIND_CONSUMER,
        };
    }

    /**
     * @param non-empty-string $name
     */
    private function activate(string $name, SpanInterface $span, ContextInterface $parent): OwnedSpan
    {
        $context = $span->storeInContext($parent);

        try {
            $activation = $this->contextStorage->attach($context);
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter->report('Context activation failed', $name, $throwable);

            OwnedSpan::detached($name, $span, $this->instrumentationFailureReporter)->finish();

            return OwnedSpan::inert($name, new OtelTraceCorrelation($parent));
        }

        $owner = OwnedSpan::activated(
            $name,
            $span,
            $activation,
            $this->instrumentationFailureReporter,
            new OtelTraceCorrelation($context),
        )->reenterableIn($this->contextStorage, $context);

        return $this->confining ? $owner->confining($this->contextStorage) : $owner;
    }
}
