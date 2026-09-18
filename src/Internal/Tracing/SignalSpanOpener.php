<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * Picks the span opener one signal gets: the real one, or the no-op when tracing is off.
 *
 * Both switches are read here, and both have to be on. The signal's own key is the
 * obvious one; the global `traces.enabled` is the one that is easy to lose, because an
 * instrumentation can be wired for its metrics half and would otherwise keep producing
 * spans after tracing was switched off wholesale.
 *
 * This is the only place in the bundle where a tracing on/off flag is read. Everything
 * below it works with whatever opener it was handed.
 *
 * Switching a signal off asks the delegate for its silent twin rather than building a bare
 * no-op, so the signal's durations keep naming the trace they were recorded inside. A
 * Doctrine query with its spans switched off is still a query this request made.
 */
final readonly class SignalSpanOpener
{
    public static function create(
        SpanOpenerInterface $delegate,
        bool $tracesEnabled,
        bool $signalEnabled,
    ): SpanOpenerInterface {
        return $tracesEnabled && $signalEnabled ? $delegate : $delegate->suppressed();
    }
}
