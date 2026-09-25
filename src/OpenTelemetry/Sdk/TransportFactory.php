<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;

/**
 * Wraps the SDK transport factory: forces the configured retry settings and returns every
 * transport inside a `BudgetedTransport`, so sends respect the flush budget.
 */
final readonly class TransportFactory implements TransportFactoryInterface
{
    /**
     * @param TransportFactoryInterface|\Closure(): TransportFactoryInterface $delegate a supplier avoids cached clients with stale timeouts
     */
    public function __construct(
        private TransportFactoryInterface|\Closure $delegate,
        private ExportGate $gate,
        private OtlpTransportSettings $settings = new OtlpTransportSettings(),
    ) {}

    /**
     * {@inheritDoc}
     */
    // @mago-expect lint:excessive-parameter-list — TransportFactoryInterface signature
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
            $factory = $this->factory();

            return $factory->create(
                $endpoint,
                $contentType,
                $this->settings->headers($headers),
                $compression,
                self::timeout($timeout, $remaining),
                $this->settings->retryDelay,
                0,
                $cacert,
                $cert,
                $key,
            );
        });
    }

    /**
     * The budget key for an endpoint: scheme, host and port, without credentials.
     */
    private static function destination(string $endpoint): string
    {
        $parts = \parse_url($endpoint);
        if ($parts === false || !\array_key_exists('host', $parts)) {
            return $endpoint;
        }

        $scheme = \strtolower($parts['scheme'] ?? 'http');
        $port = $parts['port'] ?? match ($scheme) {
            'https' => 443,
            default => 80,
        };

        return \sprintf('%s://%s:%d', $scheme, \strtolower($parts['host']), $port);
    }

    private function factory(): TransportFactoryInterface
    {
        if ($this->delegate instanceof \Closure) {
            return ($this->delegate)();
        }

        return $this->delegate;
    }

    /** The configured timeout, capped by what is left of the flush budget; zero means none. */
    private static function timeout(float $configured, float $remaining): float
    {
        if ($configured <= 0) {
            return $remaining;
        }

        return \min($configured, $remaining);
    }
}
