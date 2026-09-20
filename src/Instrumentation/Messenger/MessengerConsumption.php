<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use Nmspaced\TelemetryWeaver\Internal\Tracing\RootTrace;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * Owns a consumer operation on the middleware call stack. No scope survives a return
 * or an exception from the bus, and no worker event or service reset is needed to end it.
 * Worker listeners and transport acknowledgements run outside this processing span.
 *
 * @internal
 */
final readonly class MessengerConsumption
{
    public function __construct(
        private MessengerTelemetry $messengerTelemetry,
        private Propagation $propagation,
        private InstrumentationFailureReporter $reporter,
        private MessagingSystem $systems = new MessagingSystem(),
    ) {}

    /**
     * @param \Closure(): Envelope $work
     * @throws \Throwable
     */
    public function run(Envelope $envelope, string $destination, \Closure $work): Envelope
    {
        $operation = null;
        try {
            $destination = $destination === '' ? 'unknown' : $destination;
            $attributes = MessageAttributes::of(
                'process',
                MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE_VALUE_PROCESS,
                $destination,
                $envelope->getMessage(),
                $this->systems->ofReceiver($destination),
            );
            $propagated = $this->propagated($envelope);
            $operation = $this->messengerTelemetry->begin(
                \sprintf('process %s', $destination),
                $attributes,
                spanAttributes: $this->spanAttributes($envelope, $attributes),
                parent: $propagated,
                linkActiveSpan: $propagated->isValid(),
            );
        } catch (\Throwable $throwable) {
            $this->reporter->report('Messenger consumption instrumentation failed', 'process', $throwable);
        }

        try {
            return $work();
        } catch (\Throwable $throwable) {
            $operation?->metricAttributes([ErrorAttributes::ERROR_TYPE => MessageErrorType::of($throwable)]);
            $operation?->finish($throwable);
            throw $throwable;
        } finally {
            $operation?->finish();
        }
    }

    /**
     * Where the consumer's trace continues from.
     *
     * The producer's context becomes the parent, so the path from the request that
     * dispatched the message to the handler that ran it is one trace. That is what the
     * messaging conventions describe and what every backend expects; there is no knob,
     * because a consumer span detached from its producer is not a variant of this, it is
     * a different signal.
     *
     * When the creation context is the parent and the worker is itself running inside
     * a span — a loop somebody traces — that ambient span is added as a link, which is
     * what the messaging conventions ask for when they allow this parenting. It is not
     * linked in the rootless case: without a stamp there is no parent to trade it for,
     * and nothing says the ambient span is related to a message of unknown origin.
     *
     * No stamp at all means the message predates the instrumentation or came from
     * elsewhere. Then the span is a root, never a child of the worker's ambient context:
     * that context belongs to the previous message — and an empty carrier is exactly how
     * {@see Propagation::extract()} is told to say so.
     *
     * A propagation that throws is answered the same way rather than by the caller's
     * backstop: losing the incoming trace costs one edge between two traces, while
     * losing the operation costs the message its span, its duration and its error type.
     */
    private function propagated(Envelope $envelope): IncomingTrace
    {
        $stamp = $envelope->last(TraceContextStamp::class);

        try {
            return $this->propagation->extract($stamp instanceof TraceContextStamp ? $stamp->carrier : []);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context extraction failed', 'process', $throwable);

            return new RootTrace();
        }
    }

    /**
     * Span-only attributes: the transport's message id and whether this delivery is a
     * retry. Neither belongs in the metric — the id is unbounded, and the retry flag
     * would double every timeseries.
     *
     * @param array<non-empty-string, string> $attributes
     *
     * @return array<non-empty-string, bool|string>
     */
    private function spanAttributes(Envelope $envelope, array $attributes): array
    {
        $id = $envelope->last(TransportMessageIdStamp::class);

        if ($id instanceof TransportMessageIdStamp) {
            /** @var mixed $value */
            $value = $id->getId();

            if (\is_string($value) || \is_int($value)) {
                $attributes[MessagingIncubatingAttributes::MESSAGING_MESSAGE_ID] = (string) $value;
            }
        }

        $redelivery = $envelope->last(RedeliveryStamp::class);

        if ($redelivery instanceof RedeliveryStamp) {
            return $attributes + [MessageAttributes::REDELIVERY => true];
        }

        return $attributes;
    }
}
