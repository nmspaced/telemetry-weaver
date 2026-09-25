<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\DefaultBuckets;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\OperationBuckets;
use Nmspaced\TelemetryWeaver\Internal\Operation\BoundaryTelemetry;
use Nmspaced\TelemetryWeaver\Internal\Operation\ScopedOperation;
use Nmspaced\TelemetryWeaver\Internal\Tracing\IncomingTrace;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;

/**
 * @internal A send counts attempts; a consumption counts deliveries, independently of its eventual outcome.
 */
final readonly class MessengerTelemetry
{
    private Duration $clientDuration;

    private Duration $processDuration;

    private CounterInterface $sentMessages;

    private CounterInterface $consumedMessages;

    public function __construct(
        private BoundaryTelemetry $telemetry,
        OperationBuckets $buckets = DefaultBuckets::Messaging,
    ) {
        $metrics = $telemetry->metrics();
        $this->clientDuration = $metrics->duration(
            'messaging.client.operation.duration',
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of messaging client operations.',
        );
        $this->processDuration = $metrics->duration(
            'messaging.process.duration',
            $buckets->unit(),
            $buckets->boundaries(),
            'Duration of processing a received message.',
        );
        $this->sentMessages = $metrics->counter(
            'messaging.client.sent.messages',
            '{message}',
            'Number of messages producer attempted to send to the broker.',
        );
        $this->consumedMessages = $metrics->counter(
            'messaging.client.consumed.messages',
            '{message}',
            'Number of messages that were delivered to the application.',
        );
    }

    /**
     * @template T
     *
     * @param non-empty-string $name
     *
     * @param array<non-empty-string, string> $attributes
     *
     * @param \Closure(Span): T $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    public function send(string $name, array $attributes, \Closure $callback): mixed
    {
        try {
            return $this->telemetry
                ->operation($name)
                ->kind(SpanKind::Producer)
                ->attributes($attributes)
                ->duration($this->clientDuration, attributes: $attributes)
                ->run(
                    /** @throws \Throwable */ fn(OperationContext $context): mixed => $this->invoke($context, $callback),
                );
        } catch (\Throwable $throwable) {
            $attributes[ErrorAttributes::ERROR_TYPE] = MessageErrorType::of($throwable);
            throw $throwable;
        } finally {
            $this->sentMessages->add(1, $attributes);
        }
    }

    /**
     * A bus dispatch: a span only. It may run handlers in-process without a broker, so it is
     * not a messaging client operation; the real send is measured per transport by `send()`.
     *
     * @template T
     *
     * @param non-empty-string $busId
     * @param \Closure(Span): T $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    public function dispatch(string $busId, object $message, \Closure $callback): mixed
    {
        return $this->telemetry
            ->operation(\sprintf('symfony.messenger.dispatch %s', $message::class))
            ->kind(SpanKind::Internal)
            ->attributes(MessageAttributes::dispatch($busId, $message))
            ->run(/** @throws \Throwable */ fn(OperationContext $context): mixed => $this->invoke($context, $callback));
    }

    /**
     * @template T
     * @param \Closure(Span): T $callback
     * @return T
     * @throws \Throwable
     */
    private function invoke(OperationContext $context, \Closure $callback): mixed
    {
        try {
            return $callback($context->span());
        } catch (\Throwable $throwable) {
            $context->metricAttributes([ErrorAttributes::ERROR_TYPE => MessageErrorType::of($throwable)]);
            throw $throwable;
        }
    }

    /** @param array<non-empty-string, string> $attributes */
    public function received(array $attributes): void
    {
        $this->consumedMessages->add(1, $attributes);
    }

    /**
     * @param non-empty-string $name
     * @param array<non-empty-string, string> $attributes
     * @param array<non-empty-string, bool|string> $spanAttributes
     * @param bool $linkActiveSpan link the span the worker runs inside when the message's
     *                             own context is the parent
     */
    public function begin(
        string $name,
        array $attributes,
        array $spanAttributes,
        IncomingTrace $parent,
        bool $linkActiveSpan = false,
    ): ScopedOperation {
        $operation = $this->telemetry
            ->boundary($name)
            ->kind(SpanKind::Consumer)
            ->attributes($spanAttributes)
            ->duration($this->processDuration, attributes: $attributes)
            ->from($parent);

        if (!$linkActiveSpan) {
            return $operation->start();
        }

        return $operation->linkedToActiveSpan()->start();
    }
}
