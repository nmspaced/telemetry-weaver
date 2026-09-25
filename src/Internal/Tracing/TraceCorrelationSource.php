<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The correlation of whatever trace is running, for a measurement that has no operation of
 * its own to take it from.
 *
 * This is an ambient read, and the only one the package keeps. It is narrow on purpose:
 * the value is opaque, so a caller can carry it to a measurement and do nothing else with
 * it. Its caller is request metrics. They are collected by a subscriber independent of the
 * tracing one, so that metrics survive tracing being switched off, and the server span is
 * therefore not theirs to hold. An operation whose span is suppressed does not need this
 * source, because its opener hands it the context it runs in.
 *
 * @internal
 */
interface TraceCorrelationSource
{
    /**
     * Null when nothing is being traced, so no exemplar can name anything.
     */
    public function current(): ?TraceCorrelation;
}
