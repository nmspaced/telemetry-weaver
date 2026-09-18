<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Tracing;

/**
 * The correlation of whatever trace is running, for the two places that have no span of
 * their own to take it from.
 *
 * This is an ambient read, and the only one the package keeps. It is narrow on purpose:
 * the value is opaque, so a caller can carry it to a measurement and do nothing else with
 * it, and the two callers are the cases where the correlation genuinely cannot be threaded
 * through an owned span.
 *
 * An operation whose span is suppressed — Doctrine under `only_with_parent`, an excluded
 * HttpClient host — still records its duration, and that duration belongs to the trace the
 * suppressed operation ran inside. Request metrics are the other: they are collected by a
 * subscriber independent of the tracing one, precisely so that metrics survive tracing
 * being switched off, so the server span is not theirs to hold.
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
