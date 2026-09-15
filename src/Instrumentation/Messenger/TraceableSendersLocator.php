<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * Wraps every sender the locator hands out.
 *
 * The locator is the decoration point rather than the transport service itself because
 * its contract is one method returning `SenderInterface`, which is also one method. A
 * `messenger.transport.<name>` service is simultaneously a sender, a receiver and,
 * depending on the transport, message-count aware, listable, setupable and keepalive
 * capable; wrapping it would mean forwarding all of that correctly or narrowing what
 * callers can do — the trap `SerializerInstrumentationCompilerPass` refuses to walk into.
 */
final readonly class TraceableSendersLocator implements SendersLocatorInterface
{
    public function __construct(
        private SendersLocatorInterface $delegate,
        private MessengerTelemetry $messengerTelemetry,
        private TextMapPropagatorInterface $propagator,
        private MessagingSystem $systems = new MessagingSystem(),
    ) {}

    /**
     * @return iterable<string, SenderInterface>
     */
    #[\Override]
    public function getSenders(Envelope $envelope): iterable
    {
        foreach ($this->delegate->getSenders($envelope) as $alias => $sender) {
            $destination = \trim($alias);

            yield $alias => new TraceableSender(
                $sender,
                $this->messengerTelemetry,
                $this->propagator,
                $destination === '' ? 'unknown' : $destination,
                $this->systems->ofTransport($sender),
            );
        }
    }
}
