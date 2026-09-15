<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Client;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;

/**
 * One outgoing request being observed: the operation, and what it is about.
 *
 * The request is kept alongside the operation because the conventions require the
 * duration and both body-size histograms to be joinable on one label set, and the
 * labels are known at the start while two of the three values are only known at the
 * end. Holding the `OutgoingRequest` is how the end can still reach the labels the
 * start froze; rebuilding them from the response would risk a redirect having changed
 * the host underneath them.
 */
final readonly class ClientCall
{
    public function __construct(
        public RunningOperation $operation,
        public OutgoingRequest $request,
    ) {}
}
