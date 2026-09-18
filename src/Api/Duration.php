<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * A duration instrument, named once and recorded against many times.
 *
 * Create it once — instruments are keyed by name, and asking for the same name twice is
 * wasted work — then hand it to every operation that should be measured by it:
 *
 * ```php
 * $this->duration = $telemetry->metrics()->duration(
 *     'app.payment.duration',
 *     DurationUnit::Seconds,
 *     [0.01, 0.05, 0.1, 0.5, 1, 5],
 * );
 *
 * $telemetry->operation('payment.charge')->duration($this->duration)->run(...);
 * ```
 *
 * Opaque on purpose. Timing used to be started and stopped through this type, which meant
 * every caller carried a second lifecycle alongside the operation's own and had to know
 * that it must be started after the span, or the measurement would be correlated with the
 * wrong trace. The operation owns the clock now, so there is nothing left to get wrong and
 * nothing here to call.
 *
 * @api
 */
interface Duration {}
