<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\KnownValues;
use OpenTelemetry\SDK\Common\Configuration\Variables;

/**
 * The OTLP protocol one signal exports with, resolved before a transport is chosen.
 */
final readonly class OtlpProtocol
{
    public const string GRPC = KnownValues::VALUE_GRPC;

    public const string HTTP = 'http';

    /** Every family the SDK registry keys a transport factory by. */
    public const array FAMILIES = [self::GRPC, self::HTTP];

    /**
     * @param non-empty-string $signalVariable OTEL_EXPORTER_OTLP_<SIGNAL>_PROTOCOL
     */
    public static function of(string $signalVariable): string
    {
        return Configuration::has($signalVariable)
            ? Configuration::getEnum($signalVariable)
            : Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_PROTOCOL);
    }

    /**
     * The transport family of a protocol: `grpc`, or `http` for every HTTP encoding.
     */
    public static function family(string $protocol): string
    {
        return \explode('/', $protocol, 2)[0];
    }
}
