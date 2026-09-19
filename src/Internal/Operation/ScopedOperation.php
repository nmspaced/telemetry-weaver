<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Internal\Operation;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;

/**
 * A running operation whose context activation can be released before it finishes.
 *
 * Internal because the two things that need it are framework lifecycles, not application
 * code: an HTTP server span stops being ambient at the end of the request but is only
 * finished at terminate, and a lazy HttpClient response does the same in reverse. Both are
 * cases where the span outlives the stack frame that opened it — which is exactly the shape
 * an application should not be encouraged to reach for.
 *
 * @internal
 */
interface ScopedOperation extends RunningOperation
{
    public function detach(): void;
}
