<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\TraceCorrelation;

/**
 * A running operation whose activation can be released before it finishes, for framework
 * lifecycles where the span outlives the code that opened it.
 *
 * @internal
 */
interface ScopedOperation extends RunningOperation
{
    public function detach(): void;

    /** Detaches the context and pauses the duration; the span stays open. */
    public function suspend(): void;

    /** Reactivates the context and resumes the duration after `suspend()`. */
    public function resume(): void;

    /**
     * The trace further measurements of this work belong to, or null. Valid until the
     * operation finishes.
     */
    public function correlation(): ?TraceCorrelation;
}
