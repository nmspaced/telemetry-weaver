<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * @internal
 *
 * Sends each OTLP batch with its destination's remaining share of the flush budget. A timed-out
 * destination is refused for the rest of the flush.
 *
 * @template T of string
 * @implements TransportInterface<T>
 */
final class BudgetedTransport implements TransportInterface
{
    private bool $closed = false;

    /**
     * @param TransportInterface<T> $delegate
     * @param \Closure(float): TransportInterface<T> $create
     */
    public function __construct(
        private readonly TransportInterface $delegate,
        private readonly ExportGate $gate,
        private readonly string $destination,
        private readonly \Closure $create,
    ) {}

    #[\Override]
    public function contentType(): string
    {
        return $this->delegate->contentType();
    }

    /** @return FutureInterface<mixed> */
    #[\Override]
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->closed || $this->gate->isClosed()) {
            return new ErrorFuture(new \BadMethodCallException('Transport closed'));
        }

        $budget = $this->gate->budget();
        if (!$budget->active()) {
            return $this->delegate->send($payload, $cancellation);
        }

        $allowance = $budget->allowance($this->destination);
        if ($allowance === null) {
            return new ErrorFuture(
                new \RuntimeException(
                    'Telemetry flush budget exhausted for ' . $this->destination . ' before OTLP send',
                ),
            );
        }

        try {
            $transport = ($this->create)($allowance->seconds());
        } catch (\Throwable $throwable) {
            $allowance->failed();

            return new ErrorFuture($throwable);
        }

        try {
            return $transport
                ->send($payload, $cancellation)
                ->map(static function (mixed $result) use ($transport, $allowance): mixed {
                    $transport->shutdown();
                    $allowance->succeeded();

                    return $result;
                })
                ->catch(
                    /** @throws \Throwable */ static function (\Throwable $error) use ($transport, $allowance): never {
                        $transport->shutdown();
                        $allowance->failed();
                        throw $error;
                    },
                );
        } catch (\Throwable $throwable) {
            $transport->shutdown();
            $allowance->failed();

            return new ErrorFuture($throwable);
        }
    }

    #[\Override]
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        if ($this->closed || !$this->gate->allowsExport()) {
            return false;
        }

        $this->closed = true;

        return $this->delegate->shutdown($cancellation);
    }

    #[\Override]
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return !$this->closed && $this->gate->allowsExport() && $this->delegate->forceFlush($cancellation);
    }
}
