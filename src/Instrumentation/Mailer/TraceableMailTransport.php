<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Decorates `mailer.transports`, so a synchronous send and Messenger's mail handler
 * cross the same boundary and produce the same span. Queueing a message is not sending
 * it: the span covers the transport, and a queued mail gets its span in the consumer.
 */
final readonly class TraceableMailTransport implements TransportInterface
{
    public function __construct(
        private TransportInterface $delegate,
        private MailerTelemetry $telemetry,
    ) {}

    /** @throws \Throwable whatever the transport threw */
    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->telemetry->send(
            $message,
            /** @throws \Throwable */ fn(): ?SentMessage => $this->delegate->send($message, $envelope),
        );
    }

    #[\Override]
    public function __toString(): string
    {
        return (string) $this->delegate;
    }
}
