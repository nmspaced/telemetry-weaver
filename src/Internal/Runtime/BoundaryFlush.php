<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Runtime;

/**
 * Delivery at the end of a unit of work, as everything that ends one sees it.
 *
 * An HTTP terminate, a finished console command, a Messenger worker between messages and the
 * export gate at PHP shutdown all need the same two things said, and none of them has any
 * business knowing that saying them means calling `forceFlush()` and `shutdown()` on
 * OpenTelemetry SDK providers. That is the implementation's concern, and it lives with the
 * rest of the SDK in {@see \Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TelemetryFlusher}.
 *
 * There is still exactly one implementation and exactly one owner of delivery. The interface
 * exists so that the owner can sit on the SDK side of the perimeter while the callers stay on
 * the Symfony side of it.
 *
 * @internal
 */
interface BoundaryFlush
{
    /**
     * One unit of work has ended. Each signal is delivered on its own schedule, within the
     * boundary's shared budget.
     */
    public function atBoundary(): void;

    /**
     * The process is ending. One last collection, then the pipeline is sealed whether or not
     * every signal fit into the budget.
     */
    public function atShutdown(): void;
}
