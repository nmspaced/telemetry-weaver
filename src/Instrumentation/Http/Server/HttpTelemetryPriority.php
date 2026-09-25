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

    /** After the span outcome is recorded and while the server span is still current. */
    public const int TRACE_RESPONSE = -2049;
}
