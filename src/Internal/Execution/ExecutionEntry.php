<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Execution;

/**
 * State that lives for one execution. `complete()` reports an observed outcome; `abandon()`
 * releases resources without recording one, since nobody saw it finish.
 *
 * @internal
 */
interface ExecutionEntry
{
    public function complete(): void;

    public function abandon(): void;
}
