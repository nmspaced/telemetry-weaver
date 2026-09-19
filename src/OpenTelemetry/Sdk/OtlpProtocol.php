<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\KnownValues;
use OpenTelemetry\SDK\Common\Configuration\Variables;

/**
 * The OTLP protocol one signal exports with.
 *
 * Repeats what the upstream factories do — `OTEL_EXPORTER_OTLP_<SIGNAL>_PROTOCOL` first, then
 * the generic variable — because they do it privately and only after they have already chosen a
 * transport. Resolving it first is what lets one `OtlpTransports` serve all three signals.
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
     * The part of a protocol a transport factory is chosen by: `grpc`, or `http` for
     * `http/protobuf`, `http/json` and `http/ndjson` alike — the same cut `Registry::transportFactory()`
     * makes. Within a family the exporter's content type tells the encodings apart.
     */
    public static function family(string $protocol): string
    {
        return \explode('/', $protocol, 2)[0];
    }
}
