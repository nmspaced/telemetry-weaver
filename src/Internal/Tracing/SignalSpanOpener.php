<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * Picks a signal's span opener: the real one when both `traces.enabled` and the signal's
 * own switch are on, otherwise its suppressed twin, which keeps context and correlation.
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
