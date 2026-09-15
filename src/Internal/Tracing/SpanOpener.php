<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageInterface;

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
        try {
            $parent = $options->parent ?? $this->contextStorage->current();

            $builder = $this->tracer
                ->spanBuilder($name)
                ->setSpanKind($options->kind)
                ->setAttributes($options->attributes)
                ->setParent($parent);

            foreach ($options->links as $link) {
                $builder->addLink($link);
            }

            $span = $builder->startSpan();
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter->report('Span creation failed', $name, $throwable);

            return OwnedSpan::inert($name);
        }

        return $this->activate($name, $span, $parent);
    }

    /**
     * @param non-empty-string $name
     */
    private function activate(string $name, SpanInterface $span, ContextInterface $parent): OwnedSpan
    {
        try {
            $activation = $span->storeInContext($parent)->activate();
        } catch (\Throwable $throwable) {
            $this->instrumentationFailureReporter->report('Context activation failed', $name, $throwable);

            OwnedSpan::detached($name, $span, $this->instrumentationFailureReporter)->finish();

            return OwnedSpan::inert($name);
        }

        return OwnedSpan::activated($name, $span, $activation, $this->instrumentationFailureReporter);
    }
}
