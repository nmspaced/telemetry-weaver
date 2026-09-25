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
 * Owns a consumer operation on the middleware call stack; it always ends before the bus
 * returns or throws. Worker listeners and acknowledgements run outside it.
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
            $destination = MessageAttributes::destination($destination);
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
     * The producer's context from the message stamp. No stamp, or a failed extraction,
     * starts a new root rather than inheriting the worker's ambient context.
     */
    private function propagated(Envelope $envelope): IncomingTrace
    {
        try {
            return $this->propagation->extract($envelope->last(TraceContextStamp::class)->carrier ?? []);
        } catch (\Throwable $throwable) {
            $this->reporter->report('Context extraction failed', 'process', $throwable);

            return new RootTrace();
        }
    }

    /**
     * Span-only attributes: the transport message id and the redelivery flag, both too
     * high-cardinality for the metric.
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
