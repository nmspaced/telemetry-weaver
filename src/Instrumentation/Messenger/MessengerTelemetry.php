<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\SpanKind;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Internal\Metrics\Buckets\MessagingOperationBuckets;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\ContextInterface;
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
        private Telemetry $telemetry,
    ) {
        $buckets = new MessagingOperationBuckets();
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
     * A call to a bus: a span, and no messaging measurement at all.
     *
     * A dispatch is a Symfony operation, not a messaging client one. It may validate,
     * open a transaction and run a handler in-process without a broker ever being
     * involved, so `messaging.client.operation.duration` — which measures talking to a
     * broker — would mix handler time into send percentiles, and a synchronous bus would
     * appear to send messages it never sent. The real send is measured by `send()`, per
     * transport, inside this span. No custom dispatch histogram replaces the standard
     * one: that would be a new mandatory series nobody asked for.
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
            // ActiveOperation uses this as the default for both signals. An explicit
            // application fail() still wins, and the original exception is recorded.
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
     * @param list<SpanContextInterface> $links
     */
    public function begin(
        string $name,
        array $attributes,
        array $spanAttributes,
        ContextInterface $parent,
        array $links = [],
    ): RunningOperation {
        $operation = $this->telemetry
            ->operation($name)
            ->kind(SpanKind::Consumer)
            ->attributes($spanAttributes)
            ->duration($this->processDuration, attributes: $attributes)
            ->parent($parent);

        foreach ($links as $link) {
            $operation = $operation->link($link);
        }

        return $operation->start();
    }
}
