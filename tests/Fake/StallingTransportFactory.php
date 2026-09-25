<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/** Transports that take a set time to answer on a frozen clock, failing past their timeout. */
final class StallingTransportFactory implements TransportFactoryInterface
{
    /** @var list<array{endpoint: string, timeout: float}> */
    public array $sends = [];

    /** @param array<string, float> $answerSeconds by host; unlisted hosts answer at once */
    public function __construct(
        private readonly FrozenClock $clock,
        private readonly array $answerSeconds = [],
    ) {}

    /**
     * @return TransportInterface<string>
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
        /** @implements TransportInterface<string> */
        return new readonly class($this, $endpoint, $contentType, $timeout) implements TransportInterface {
            public function __construct(
                private StallingTransportFactory $collectors,
                private string $endpoint,
                private string $contentType,
                private float $timeout,
            ) {}

            #[\Override]
            public function contentType(): string
            {
                return $this->contentType;
            }

            #[\Override]
            public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
            {
                return $this->collectors->answer($this->endpoint, $this->timeout);
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

    /** @return FutureInterface<null> */
    public function answer(string $endpoint, float $timeout): FutureInterface
    {
        $this->sends[] = ['endpoint' => $endpoint, 'timeout' => $timeout];
        $answer = $this->answerSeconds[\strtolower((string) \parse_url($endpoint, \PHP_URL_HOST))] ?? 0.0;
        if ($answer > $timeout) {
            $this->clock->advanceSeconds($timeout);

            return new ErrorFuture(new \RuntimeException('Operation timed out for ' . $endpoint));
        }

        $this->clock->advanceSeconds($answer);

        return new CompletedFuture(null);
    }
}
