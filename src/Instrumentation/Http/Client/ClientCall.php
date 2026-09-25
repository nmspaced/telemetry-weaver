<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;

/**
 * An observed outgoing request: its operation and the request whose labels were frozen at
 * the start, so the end records body sizes under the same labels.
 */
final readonly class ClientCall
{
    public function __construct(
        public ScopedOperation $operation,
        public OutgoingRequest $request,
    ) {}
}
