<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;

/**
 * @internal The transport factory an OTLP exporter the bundle builds is handed.
 *
 * The upstream OTLP exporter factories take one as their only injection point. Which one it is
 * — the bundle's budgeted factory or an application's own — is decided by the container, not
 * by the exporter factories that ask.
 */
interface OtlpTransports
{
    /**
     * @param string $protocol a value of OTEL_EXPORTER_OTLP_PROTOCOL, see `OtlpProtocol`
     */
    public function forProtocol(string $protocol): TransportFactoryInterface;
}
