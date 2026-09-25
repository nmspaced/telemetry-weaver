<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * Wraps every sender the locator hands out. The locator is decorated instead of the transport
 * services, which implement many more interfaces than a sender.
 */
final readonly class TraceableSendersLocator implements SendersLocatorInterface
{
    public function __construct(
        private SendersLocatorInterface $delegate,
        private MessengerTelemetry $messengerTelemetry,
        private Propagation $propagation,
        private InstrumentationFailureReporter $reporter,
        private MessagingSystem $systems = new MessagingSystem(),
    ) {}

    /**
     * @return iterable<string, SenderInterface>
     */
    #[\Override]
    public function getSenders(Envelope $envelope): iterable
    {
        foreach ($this->delegate->getSenders($envelope) as $alias => $sender) {
            yield $alias => new TraceableSender(
                $sender,
                $this->messengerTelemetry,
                $this->propagation,
                MessageAttributes::destination(\trim($alias)),
                $this->reporter,
                $this->systems->ofTransport($sender),
            );
        }
    }
}
