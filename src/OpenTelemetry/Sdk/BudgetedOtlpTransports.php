<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Registry;

/**
 * The bundle's transports: the SDK factory for the protocol behind a `TransportFactory`.
 * Resolved per call, because the SDK factory caches its client and timeout.
 */
final readonly class BudgetedOtlpTransports implements OtlpTransports
{
    public function __construct(
        private ExportGate $gate,
        private OtlpTransportSettings $settings = new OtlpTransportSettings(),
    ) {}

    #[\Override]
    public function forProtocol(string $protocol): TransportFactoryInterface
    {
        return new TransportFactory(
            static fn(): TransportFactoryInterface => Registry::transportFactory($protocol),
            $this->gate,
            $this->settings,
        );
    }
}
