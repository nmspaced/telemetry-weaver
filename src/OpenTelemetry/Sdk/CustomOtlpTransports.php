<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;

/**
 * An application's own transport factories (`sdk.otlp.transport_factories`), one per protocol family.
 *
 * Keyed by family rather than shared by every protocol, because a factory cannot tell from its
 * arguments which protocol a call is for: gRPC and `http/protobuf` both arrive as
 * `application/x-protobuf`, and a signal-specific endpoint is passed through verbatim. With
 * traces on gRPC and metrics on HTTP, one shared factory would send one of them over the wrong
 * wire. Within a family the content type is enough to choose an encoding.
 *
 * A family the application did not name keeps the bundle's transport, budget and settings
 * included. For a named family none of the bundle's transport layer applies: no flush budget, no
 * retry override, no `exporter_otlp_headers`. What stays is the layer above — the exporter is still
 * a `Resilient*Exporter`, so a throw or a rejected future never reaches the application and
 * nothing is exported once the pipeline is sealed. What goes is the bound on waiting: a transport
 * that ignores its timeout holds the boundary flush for as long as it waits.
 *
 * The protocol still matters upstream: the OTLP exporter factory looks the protocol's transport
 * up in the SDK registry before it uses the one it was given, and derives the content type from it.
 */
final readonly class CustomOtlpTransports implements OtlpTransports
{
    /**
     * @param array<string, TransportFactoryInterface> $factories keyed by `OtlpProtocol::family()`
     * @param OtlpTransports $fallback the bundle's transports, for every family not in `$factories`
     */
    public function __construct(
        private array $factories,
        private OtlpTransports $fallback,
    ) {}

    #[\Override]
    public function forProtocol(string $protocol): TransportFactoryInterface
    {
        return $this->factories[OtlpProtocol::family($protocol)] ?? $this->fallback->forProtocol($protocol);
    }
}
