<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use PHPUnit\Framework\Assert;

/**
 * Records the ten arguments a transport factory is called with. The whole point of
 * the decorator under test is which of them it replaces and which it passes through,
 * so the assertion is on the argument list itself.
 */
final class RecordingTransportFactory implements TransportFactoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @return array<string, mixed> */
    public function call(int $index = 0): array
    {
        return $this->calls[$index] ?? Assert::fail('no recorded call at index ' . $index);
    }

    public function argument(string $key, int $index = 0): mixed
    {
        $call = $this->call($index);
        if (!\array_key_exists($key, $call)) {
            Assert::fail('call ' . $index . ' has no argument "' . $key . '"');
        }

        return $call[$key];
    }

    /**
     * @return TransportInterface<string>
     */
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
        $this->calls[] = [
            'endpoint' => $endpoint,
            'contentType' => $contentType,
            'headers' => $headers,
            'compression' => $compression,
            'timeout' => $timeout,
            'retryDelay' => $retryDelay,
            'maxRetries' => $maxRetries,
            'cacert' => $cacert,
            'cert' => $cert,
            'key' => $key,
        ];

        /** @implements TransportInterface<string> */
        return new readonly class($contentType) implements TransportInterface {
            public function __construct(
                private string $contentType,
            ) {}

            #[\Override]
            public function contentType(): string
            {
                return $this->contentType;
            }

            #[\Override]
            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                return new CompletedFuture(null);
            }

            #[\Override]
            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            #[\Override]
            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
    }
}
