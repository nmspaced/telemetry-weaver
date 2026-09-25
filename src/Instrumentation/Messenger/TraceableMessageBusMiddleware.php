<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Spans a bus dispatch from the head of the middleware chain, and wraps handling of a
 * received message in a consumer operation owned by this call.
 */
final readonly class TraceableMessageBusMiddleware implements MiddlewareInterface
{
    /**
     * @param non-empty-string $busId
     */
    public function __construct(
        private MessengerTelemetry $messengerTelemetry,
        private string $busId,
        private MessengerConsumption $consumption,
    ) {}

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $received = $envelope->last(ReceivedStamp::class);
        if ($received !== null || $envelope->last(ConsumedByWorkerStamp::class) !== null) {
            return $this->consumption->run(
                $envelope,
                $received?->getTransportName() ?? '',
                /** @throws \Throwable */
                static fn(): Envelope => $stack->next()->handle($envelope, $stack),
            );
        }

        return $this->messengerTelemetry->dispatch(
            $this->busId,
            $envelope->getMessage(),
            /** @throws \Throwable */
            static fn(): Envelope => $stack->next()->handle($envelope, $stack),
        );
    }
}
