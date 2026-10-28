<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher;

use Nmspaced\TelemetryWeaver\Instrumentation\CodeFunction;
use Nmspaced\TelemetryWeaver\Instrumentation\HandlerCall;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;

/**
 * One span per call of a listener in the configured namespaces, only inside an existing trace.
 *
 * The namespace is checked on every call rather than remembered per class: a worker would keep
 * that map for its lifetime, and a prefix comparison costs less than the lookup would save.
 *
 * @internal
 */
final readonly class ListenerTelemetry
{
    private const string EVENT_NAME = 'listener.event.name';

    /**
     * Never traced, whatever the configuration says: the bundle's own listeners open the server
     * span and propagate it into the response, and a listener span around them would stand in
     * for it.
     */
    private const string BUNDLE_NAMESPACE = 'Nmspaced\\TelemetryWeaver\\Instrumentation\\';

    /**
     * @param list<non-empty-string> $namespaces class name prefixes of the listeners to trace
     */
    public function __construct(
        private BoundaryTelemetry $telemetry,
        private array $namespaces,
    ) {}

    /**
     * @param \Closure(): void $call
     *
     * @throws \Throwable
     */
    public function call(callable $listener, string $eventName, \Closure $call): void
    {
        $function = CodeFunction::of(self::unwrapped($listener));

        if (!$this->traces($function->name)) {
            $call();

            return;
        }

        HandlerCall::run(
            $this->telemetry
                ->boundary($function->spanName)
                ->attributes([
                    CodeAttributes::CODE_FUNCTION_NAME => $function->name,
                    self::EVENT_NAME => $eventName,
                ])
                ->onlyInsideTrace(),
            $call,
        );
    }

    private function traces(string $function): bool
    {
        if (\str_starts_with($function, self::BUNDLE_NAMESPACE)) {
            return false;
        }

        return \array_any($this->namespaces, static fn(string $prefix): bool => \str_starts_with($function, $prefix));
    }

    /** The profiler wraps each listener in debug mode; the span is named after the listener itself. */
    private static function unwrapped(callable $listener): callable
    {
        if (!$listener instanceof WrappedListener) {
            return $listener;
        }

        $wrapped = $listener->getWrappedListener();

        return \is_callable($wrapped) ? $wrapped : $listener;
    }
}
