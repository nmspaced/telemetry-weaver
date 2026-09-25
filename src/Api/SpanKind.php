<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * What an operation's span represents in a trace. Mapped to OpenTelemetry's values by
 * the adapter, so the API needs no OpenTelemetry types.
 *
 * @api
 */
enum SpanKind
{
    case Internal;

    case Server;

    case Client;

    case Producer;

    case Consumer;
}
