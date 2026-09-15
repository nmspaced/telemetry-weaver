<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;

/**
 * The attributes of the points a message passes through.
 *
 * Send and process are messaging operations: their set is frozen before the call and
 * handed unchanged to both the span and the histogram. A dispatch is not one, and gets
 * only Symfony attributes (see `dispatch()`).
 *
 * `messaging.system` is the broker when the transport identifies one the conventions have
 * a value for, and `symfony` otherwise (see `MessagingSystem`). It is a label on both
 * messaging metrics, and bounded: one value per transport class.
 */
final readonly class MessageAttributes
{
    /** The framework fallback, for transports that name no broker the conventions know. */
    public const string SYSTEM = 'symfony';

    /**
     * The message class. A custom attribute under its own namespace on purpose:
     * `messaging.message.type` does not exist in the conventions registry (only the
     * RocketMQ-specific one does), and the naming rules forbid inventing a name inside
     * an OpenTelemetry namespace.
     */
    public const string MESSAGE_CLASS = 'symfony.messenger.message.class';

    /**
     * Whether this delivery is a retry. Span-only: as a metric label it would double
     * every timeseries to answer a question the retry counter of the transport already
     * answers.
     */
    public const string REDELIVERY = 'symfony.messenger.redelivery';

    /**
     * The bus a dispatch went through. Custom, like the rest of the dispatch span: a bus
     * is not a messaging destination, and `messaging.destination.name` on it would put an
     * in-process call next to real queues in every messaging view.
     */
    public const string BUS = 'symfony.messenger.bus';

    /**
     * A dispatch is a Symfony operation, not a messaging one, so it carries none of the
     * `messaging.*` attributes — only the bus and the message class.
     *
     * @param non-empty-string $busId
     *
     * @return array<non-empty-string, string>
     */
    public static function dispatch(string $busId, object $message): array
    {
        return [
            self::BUS => $busId,
            self::MESSAGE_CLASS => $message::class,
        ];
    }

    /**
     * @param non-empty-string $operation send or process
     * @param non-empty-string $type one of the MESSAGING_OPERATION_TYPE_VALUE_* constants
     * @param non-empty-string $destination the transport name
     * @param non-empty-string $system the broker, or the framework fallback (see `MessagingSystem`)
     *
     * @return array<non-empty-string, string>
     */
    public static function of(
        string $operation,
        string $type,
        string $destination,
        object $message,
        string $system = self::SYSTEM,
    ): array {
        return [
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => $system,
            MessagingIncubatingAttributes::MESSAGING_OPERATION_NAME => $operation,
            MessagingIncubatingAttributes::MESSAGING_OPERATION_TYPE => $type,
            MessagingIncubatingAttributes::MESSAGING_DESTINATION_NAME => $destination,
            self::MESSAGE_CLASS => $message::class,
        ];
    }
}
