<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;

/**
 * Forces the retry settings of every transport the SDK builds.
 *
 * The OTLP exporter factories call `create()` with five of its ten arguments, so
 * `$retryDelay = 100` and `$maxRetries = 3` are whatever the interface defaults say
 * and cannot be reached from configuration. A retry sleeps in the calling process, and
 * `Resilient*Exporter` cannot help with that: it catches throws and rejected futures,
 * not a synchronous sleep.
 *
 * So the two retry arguments are *overridden*, not defaulted, with the values of
 * `OtlpTransportSettings`; every other argument — including all three TLS paths — is
 * forwarded untouched. Headers ride along: the settings merge the configured ones over
 * OTEL_EXPORTER_OTLP_HEADERS.
 *
 * Every transport comes back inside a `BudgetedTransport` bound to the pipeline's
 * `ExportGate`. Outside a flush boundary it forwards untouched; inside one it caps each
 * send to its destination's share of the budget (see `FlushBudget`), and after
 * finalization it refuses to send at all. The bundle builds no unbudgeted variant; an
 * application that configures `sdk.otlp.transport_factories` replaces this class for the
 * protocol families it names and gives the budget up with it (see `CustomOtlpTransports`).
 */
final readonly class TransportFactory implements TransportFactoryInterface
{
    /**
     * @param TransportFactoryInterface|\Closure(): TransportFactoryInterface $delegate factory supplier avoids cached HTTP clients with stale timeouts
     */
    public function __construct(
        private TransportFactoryInterface|\Closure $delegate,
        private ExportGate $gate,
        private OtlpTransportSettings $settings = new OtlpTransportSettings(),
    ) {}

    /**
     * {@inheritDoc}
     */
    // @mago-expect lint:excessive-parameter-list — the signature is TransportFactoryInterface's
    #[\Override]
    public function create(
        string $endpoint,
        string $contentType,
        array $headers = [],
        $compression = null,
        float $timeout = 10.,
        int $retryDelay = 100,
        int $maxRetries = 3,
        ?string $cacert = null,
        ?string $cert = null,
        ?string $key = null,
    ): TransportInterface {
        $transport = $this->factory()->create(
            $endpoint,
            $contentType,
            $this->settings->headers($headers),
            $compression,
            $timeout,
            $this->settings->retryDelay,
            $this->settings->maxRetries,
            $cacert,
            $cert,
            $key,
        );

        $destination = self::destination($endpoint);
        $this->gate->budget()->register($destination);

        return new BudgetedTransport($transport, $this->gate, $destination, function (float $remaining) use (
            $endpoint,
            $contentType,
            $headers,
            $compression,
            $timeout,
            $cacert,
            $cert,
            $key,
        ): TransportInterface {
            // PsrTransportFactory caches its client after create(). Obtain a fresh
            // factory so the remaining timeout actually reaches a new HTTP client.
            $factory = $this->factory();

            return $factory->create(
                $endpoint,
                $contentType,
                $this->settings->headers($headers),
                $compression,
                $timeout > 0 ? \min($timeout, $remaining) : $remaining,
                $this->settings->retryDelay,
                0,
                $cacert,
                $cert,
                $key,
            );
        });
    }

    /**
     * The collector a transport talks to, as the flush budget keys it: scheme, host and port.
     * `/v1/traces` and `/v1/metrics` on one collector are one destination — they fail together.
     * Credentials in the URL are left out; the key appears in diagnostics.
     */
    private static function destination(string $endpoint): string
    {
        $parts = \parse_url($endpoint);
        if ($parts === false || !\array_key_exists('host', $parts)) {
            return $endpoint;
        }

        $scheme = \strtolower($parts['scheme'] ?? 'http');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . \strtolower($parts['host']) . ':' . $port;
    }

    private function factory(): TransportFactoryInterface
    {
        return $this->delegate instanceof \Closure ? ($this->delegate)() : $this->delegate;
    }
}
