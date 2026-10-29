<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\EventDispatcher;

use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Instrumentation\CodeFunction;
use Nmspaced\TelemetryWeaver\Instrumentation\HandlerCall;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;

/**
 * One span per call of a listener in the configured namespaces, only inside an existing trace.
 *
 * Most listeners a request runs are the framework's, so the decision comes first and costs a
 * prefix comparison on the class name; the listener is named and an operation built only for
 * the few that are traced. The namespace is checked on every call rather than remembered per
 * class: a worker would keep that map for its lifetime, and the comparison costs less than the
 * lookup would save.
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
        private ActiveTrace $activeTrace,
        private array $namespaces,
    ) {}

    /**
     * The listener's name when this call gets a span, null when it runs untraced.
     *
     * Called for every listener of every event, so it avoids what is costly at that rate. The
     * listener is taken as `mixed` rather than `callable`, whose check looks the method up on
     * each call, and is only confirmed callable on the rare path that names it. Its owner is
     * read off the array a compiled listener is before anything else is tried. Without an
     * active trace there would be no span anyway; checking that before naming the listener
     * spares building an operation that would only be suppressed.
     */
    public function traced(mixed $listener): ?CodeFunction
    {
        if ($listener instanceof WrappedListener) {
            return $this->traced($listener->getWrappedListener());
        }

        $owner = self::owner($listener);

        if ($owner === null || !$this->inNamespaces($owner) || $this->activeTrace->current() === null) {
            return null;
        }

        return \is_callable($listener) ? CodeFunction::of($listener) : null;
    }

    /**
     * @param \Closure(): void $call
     *
     * @throws \Throwable
     */
    public function call(CodeFunction $listener, string $eventName, \Closure $call): void
    {
        HandlerCall::run(
            $this->telemetry
                ->boundary($listener->spanName)
                ->attributes([
                    CodeAttributes::CODE_FUNCTION_NAME => $listener->name,
                    self::EVENT_NAME => $eventName,
                ])
                ->onlyInsideTrace(),
            $call,
        );
    }

    private function inNamespaces(string $owner): bool
    {
        if (\str_starts_with($owner, self::BUNDLE_NAMESPACE)) {
            return false;
        }

        foreach ($this->namespaces as $prefix) {
            if (\str_starts_with($owner, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class a listener belongs to, or the function's own name; null for anything else.
     * Only a closure has to be reflected for it, and closures are rare among compiled
     * listeners.
     */
    private static function owner(mixed $listener): ?string
    {
        if (\is_array($listener)) {
            // @mago-expect analysis:mixed-assignment — the target of [target, method]; narrowed below
            $listener = $listener[0] ?? null;
        }

        if (\is_string($listener)) {
            return $listener;
        }

        if ($listener instanceof \Closure) {
            return CodeFunction::of($listener)->name;
        }

        return \is_object($listener) ? $listener::class : null;
    }
}
