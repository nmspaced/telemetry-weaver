<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * One PRODUCER span per transport a message is sent to, and the trace stamp that
 * transport's consumer continues from.
 *
 * Messenger's send events fire once per message, so the sender is wrapped instead.
 */
final readonly class TraceableSender implements SenderInterface
{
    /**
     * @param non-empty-string $destination the transport alias
     * @param non-empty-string $system the transport's broker, or the framework fallback
     */
    // @mago-expect lint:excessive-parameter-list — decorator dependencies
    public function __construct(
        private SenderInterface $delegate,
        private MessengerTelemetry $messengerTelemetry,
        private Propagation $propagation,
        private string $destination,
        private InstrumentationFailureReporter $reporter,
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
     * Replaces any existing stamp with this send's context, since the envelope returned by
     * one transport is what the next transport receives.
     */
    private function stamped(Envelope $envelope): Envelope
    {
        $envelope = $envelope->withoutAll(TraceContextStamp::class);

        try {
            $headers = $this->propagation->injectCurrent();
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context injection failed', 'messenger send', $throwable);

            return $envelope;
        }

        if ($headers === []) {
            return $envelope;
        }

        return $envelope->with(new TraceContextStamp($headers));
    }
}
