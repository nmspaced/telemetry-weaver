<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server;

final readonly class HttpTelemetryPriority
{
    public const int TRACE_REQUEST = 2048;

    public const int METRIC_REQUEST = 2047;

    public const int TRACE_ROUTE = 31;

    public const int METRIC_ROUTE = 30;

    public const int METRIC_TERMINATE = -2047;

    public const int TRACE_TERMINATE = -2048;

    /**
     * After the server span's outcome has been recorded on kernel.response, and still well
     * before kernel.finish_request releases its activation — which is what the response
     * propagator reads.
     */
    public const int TRACE_RESPONSE = -2049;
}
