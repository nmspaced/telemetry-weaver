<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;

/**
 * @internal
 *
 * Provides the transport factory for OTLP exporters the bundle builds.
 */
interface OtlpTransports
{
    /**
     * @param string $protocol a value of OTEL_EXPORTER_OTLP_PROTOCOL, see `OtlpProtocol`
     */
    public function forProtocol(string $protocol): TransportFactoryInterface;
}
