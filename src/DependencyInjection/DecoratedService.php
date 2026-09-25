<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

/**
 * Ids of the services this bundle adds next to a service it instruments, suffixed
 * `.open_telemetry` so their origin is obvious in `debug:container`.
 */
final readonly class DecoratedService
{
    private const string SUFFIX = 'open_telemetry';

    /** `mailer.transports` becomes `mailer.transports.open_telemetry`. */
    public static function id(string $decoratedId): string
    {
        return \sprintf('%s.%s', $decoratedId, self::SUFFIX);
    }

    /** Where the decorated service moves; named explicitly so arguments can reference it. */
    public static function innerId(string $decoratedId): string
    {
        return \sprintf('%s.inner', self::id($decoratedId));
    }

    /**
     * A service registered beside another without decorating it, such as a bus middleware:
     * `messenger.bus.default` becomes `messenger.bus.default.open_telemetry_middleware`.
     *
     * @param non-empty-string $role what the service is, in one word
     */
    public static function siblingId(string $baseId, string $role): string
    {
        return \sprintf('%s.%s_%s', $baseId, self::SUFFIX, $role);
    }
}
