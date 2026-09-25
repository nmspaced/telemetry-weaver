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
 * Turns a span description into a started, activated OpenTelemetry span.
 *
 * This is where every decision the rest of the package refuses to make gets made: which
 * context an operation descends from, what an unusable incoming trace means, and how the
 * package's own `SpanKind` maps onto the SDK's integers.
 */
final readonly class SpanOpener implements SpanOpenerInterface
{
    public function __construct(
        private TracerInterface $tracer,
        private ContextStorageInterface $contextStorage,
        private InstrumentationFailureReporter $instrumentationFailureReporter,
    ) {}

    /**
     * @param non-empty-string $name
     */
    #[\Override]
    public function open(string $name, SpanOptions $options): OwnedSpan
    {
        $ambient = $this->contextStorage->current();

        // `only_with_parent`, decided here rather than by the instrumentation that asked.
        // A policy object that reads the current span to answer this is an ambient
        // dependency in the one layer that must not have one; a flag on the description is
        // a declaration, and the answer belongs to whoever owns the context anyway.
        // The operation still owns its context: suppressing the span must not drop the
        // baggage it was given.
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

            return OwnedSpan::inert($name, new OtelTraceCorrelation($parent));
        }

        return $this->activate($name, $span, $parent);
    }

    /**
     * No span, but the same context: a signal whose spans are off still continues the
     * trace its boundary received and still carries its baggage.
     */
    #[\Override]
    public function suppressed(): ContextOnlyOpener
    {
        return new ContextOnlyOpener($this->contextStorage, $this->instrumentationFailureReporter);
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
            // `$this->contextStorage->attach()` rather than `$context->activate()`: the latter
            // is `Context::storage()->attach()`, so a span read out of the injected storage
            // would be activated in whichever storage the process installed last. In the
            // container the two are the same object and the difference is invisible; in a test
            // that swaps in a fiber-bound storage, or a worker that rebuilt its container, the
            // parent lookup above and this activation would be two different places. Every
            // other adapter here takes the storage for exactly this reason.
            $activation = $this->contextStorage->attach($context);
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter->report('Context activation failed', $name, $throwable);

            OwnedSpan::detached($name, $span, $this->instrumentationFailureReporter)->finish();

            return OwnedSpan::inert($name, new OtelTraceCorrelation($parent));
        }

        // The child context, not the parent: a duration recorded for this operation names
        // this operation's span, whether or not the activation is still in place when it
        // is finally recorded.
        return OwnedSpan::activated(
            $name,
            $span,
            $activation,
            $this->instrumentationFailureReporter,
            new OtelTraceCorrelation($context),
        )->reenterableIn($this->contextStorage, $context);
    }
}
