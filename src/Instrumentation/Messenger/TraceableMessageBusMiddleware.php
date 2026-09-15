<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Spans one call to a bus, from the first middleware to the last.
 *
 * Placed at the head of the chain so the span covers everything the dispatch does —
 * validation, the doctrine transaction, the send to a transport — which is what makes
 * it the answer to "what did calling the bus cost". That answer lives on the span only:
 * the dispatch records no messaging metric (see `MessengerTelemetry::dispatch()`).
 *
 * A received message gets a consumer operation instead, including synchronous transports. Its scope is owned by this
 * call, so failures in worker event listeners cannot strand it.
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
                $received?->getTransportName() ?? 'unknown',
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
