<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * What an operation's span represents in a trace.
 *
 * A plain enum rather than one backed by OpenTelemetry's integers: the mapping is a detail
 * of the tracing backend, and keeping it here would put an `OpenTelemetry\API\Trace` import
 * in the one namespace whose whole purpose is that an application needs no OpenTelemetry
 * type to describe its own work. {@see \Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter\SpanOpener}
 * holds the translation.
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
