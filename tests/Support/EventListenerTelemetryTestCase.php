<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\ListenerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher\TracingEventDispatcher;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\OtelActiveTrace;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;

/**
 * A {@see TracingEventDispatcher} that traces the listeners under `Tests\Fake`.
 *
 * @internal
 */
abstract class EventListenerTelemetryTestCase extends TelemetryTestCase
{
    protected const string TRACED = 'Nmspaced\\TelemetryWeaver\\Tests\\Fake\\';

    protected TracingEventDispatcher $dispatcher;

    protected ?SpanInterface $parent = null;

    private ?ScopeInterface $parentScope = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = $this->dispatcherTracing([self::TRACED]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->parentScope?->detach();
        parent::tearDown();
    }

    /** @param list<non-empty-string> $namespaces */
    protected function dispatcherTracing(array $namespaces): TracingEventDispatcher
    {
        return new TracingEventDispatcher(
            new ListenerTelemetry(
                TelemetryFactory::tracing($this->spans, $this->reporter),
                new OtelActiveTrace($this->contextStorage),
                $namespaces,
            ),
        );
    }

    protected function activateParent(): void
    {
        $this->parent = $this->tracer->spanBuilder('parent')->startSpan();
        $this->parentScope = $this->parent->storeInContext(Context::getCurrent())->activate();
    }
}
