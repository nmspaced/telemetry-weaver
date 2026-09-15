<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Registry;

/**
 * The bundle's own transports: the SDK's registered factory for the protocol, behind a
 * `TransportFactory` that applies the retry and header settings and the flush budget.
 *
 * The SDK factory is resolved through a supplier rather than once, because `PsrTransportFactory`
 * caches its HTTP client — and with it the timeout — after the first `create()`; a budgeted send
 * needs a fresh one to carry its remaining share.
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
