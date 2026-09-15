<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;

enum SpanKind: int
{
    case Internal = OtelSpanKind::KIND_INTERNAL;

    case Server = OtelSpanKind::KIND_SERVER;

    case Client = OtelSpanKind::KIND_CLIENT;

    case Producer = OtelSpanKind::KIND_PRODUCER;

    case Consumer = OtelSpanKind::KIND_CONSUMER;
}
