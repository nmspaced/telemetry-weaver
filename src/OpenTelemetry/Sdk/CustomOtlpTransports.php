<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;

/**
 * An application's own transport factories, one per protocol family.
 *
 * Named families bypass the bundle's flush budget, retry override and headers; exporters stay
 * resilient. Unnamed families keep the bundle's transports.
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
