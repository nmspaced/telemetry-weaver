<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Messenger;

use OpenTelemetry\SemConv\Incubating\Attributes\MessagingIncubatingAttributes;

/**
 * Attributes for send, process and dispatch.
 *
 * Send and process get the `messaging.*` set, shared by span and histogram; a dispatch is
 * not a messaging operation and gets only Symfony attributes.
 */
final readonly class MessageAttributes
{
    /** `messaging.system` for transports whose broker the conventions do not name. */
    public const string SYSTEM = 'symfony';

    /** The message class; the conventions define no generic attribute for it. */
    public const string MESSAGE_CLASS = 'symfony.messenger.message.class';

    /** Whether this delivery is a retry. Span-only. */
    public const string REDELIVERY = 'symfony.messenger.redelivery';

    /** The bus a dispatch went through; a bus is not a messaging destination. */
    public const string BUS = 'symfony.messenger.bus';

    /** A destination for a transport without a usable name. */
    public const string UNKNOWN_DESTINATION = 'unknown';

    /** @return non-empty-string */
    public static function destination(string $name): string
    {
        if ($name === '') {
            return self::UNKNOWN_DESTINATION;
        }

        return $name;
    }

    /**
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
