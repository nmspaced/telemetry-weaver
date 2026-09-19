<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * One span per transport a message is sent to, and the trace context that transport's
 * consumer will continue from.
 *
 * The send events Messenger dispatches (`SendMessageToTransportsEvent` and its Sent
 * counterpart) fire once per *message*, before and after the loop over senders. A
 * message routed to two transports would get one span named after one of them, one
 * duration covering two brokers, and a single stamp both consumers would point at.
 * Wrapping the sender moves the span to where the send actually happens.
 *
 * The stamp is added here, last thing before the transport serializes the envelope, so
 * each transport carries the context of its own send.
 */
final readonly class TraceableSender implements SenderInterface
{
    /**
     * @param non-empty-string $destination the transport alias
     * @param non-empty-string $system the transport's broker, or the framework fallback
     */
    public function __construct(
        private SenderInterface $delegate,
        private MessengerTelemetry $messengerTelemetry,
        private Propagation $propagation,
        private string $destination,
        private string $system = MessageAttributes::SYSTEM,
    ) {}

    /**
     * @throws \Throwable
     */
    #[\Override]
    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();

        return $this->messengerTelemetry->send(
            \sprintf('send %s', $this->destination),
            MessageAttributes::of(
                'send',
                MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_SEND,
                $this->destination,
                $message,
                $this->system,
            ),
            /** @throws \Throwable */
            fn(): Envelope => $this->delegate->send($this->stamped($envelope)),
        );
    }

    /**
     * Injected inside the span, so the carrier names *this* send.
     *
     * Any stamp already on the envelope is dropped first, and that is the whole point of
     * doing this per sender. `SendMessageMiddleware` reassigns the envelope from each
     * send's return value, so the second transport receives what the first one produced;
     * keeping the existing stamp would make both consumers continue from the first
     * send's span, which is exactly the bug per-transport spans exist to fix.
     *
     * A re-send — to a failure transport, or by a retry — is re-stamped for the same
     * reason: its consumer belongs under the send that actually delivered it, and the
     * chain back to the original producer is still there through that send's own parent.
     */
    private function stamped(Envelope $envelope): Envelope
    {
        $headers = $this->propagation->injectCurrent();

        if ($headers === []) {
            return $envelope;
        }

        return $envelope->withoutAll(TraceContextStamp::class)->with(new TraceContextStamp($headers));
    }
}
