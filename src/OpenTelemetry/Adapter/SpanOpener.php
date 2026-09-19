<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Tracing\NoOpSpanOpener;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOpenerInterface;
use Nmspaced\TelemetryWeaver\Internal\Tracing\SpanOptions;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceRelations;
use OpenTelemetry\API\Baggage\Baggage;
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
        if ($options->onlyInsideTrace && !Span::fromContext($ambient)->getContext()->isValid()) {
            return OwnedSpan::inert($name, new OtelTraceCorrelation($ambient));
        }

        $parent = self::withBaggage(self::parentOf($options->relations, $ambient), $options->baggage);

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

    #[\Override]
    public function suppressed(): SpanOpenerInterface
    {
        return NoOpSpanOpener::suppressing(new OtelTraceCorrelationSource($this->contextStorage));
    }

    /**
     * No incoming trace continues whatever is running; one that arrived replaces it, valid
     * or not. An invalid one is the whole point of the distinction: a boundary that carried
     * nothing starts a new trace, where inheriting the ambient context would attach a fresh
     * request to the remains of the last one.
     */
    private static function parentOf(TraceRelations $relations, ContextInterface $ambient): ContextInterface
    {
        $incoming = $relations->parent;

        if ($incoming === null) {
            return $ambient;
        }

        return $incoming instanceof OtelIncomingTrace ? $incoming->context : $ambient;
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
     * Baggage goes into the context the span is created in, so the span's own context
     * carries it and every outgoing call made inside the operation propagates it.
     *
     * Applied before `startSpan()` rather than after: a sampler may read baggage, and one
     * that does would otherwise see the parent's entries instead of this operation's.
     *
     * @param array<non-empty-string, string> $entries
     */
    private static function withBaggage(ContextInterface $parent, array $entries): ContextInterface
    {
        if ($entries === []) {
            return $parent;
        }

        $builder = Baggage::fromContext($parent)->toBuilder();

        foreach ($entries as $key => $value) {
            $builder->set($key, $value);
        }

        return $builder->build()->storeInContext($parent);
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
            $activation = $context->activate();
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
        );
    }
}
