<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection;

/**
 * The service ids this bundle adds next to a service it instruments.
 *
 * The suffix is the bundle's own name rather than an abbreviation of it. These ids show
 * up in `debug:container`, in a circular-reference trace and in the dumped container,
 * where the reader is someone trying to work out what put an unfamiliar object between
 * their code and the service they configured. `mailer.transports.otel` requires knowing
 * what "otel" is and which package chose it; `mailer.transports.open_telemetry` matches
 * the configuration key, the parameter namespace and every other id the bundle owns, so
 * the answer is in the name.
 *
 * Shared rather than a constant per pass. Five passes each declared their own
 * `DECORATOR_SUFFIX` with the same value, which made the naming a coincidence that held
 * five times rather than a rule — and the inner-service id, which has to agree with it,
 * was then built by hand at each call site.
 */
final readonly class DecoratedService
{
    private const string SUFFIX = 'open_telemetry';

    /**
     * The decorator's own id: `mailer.transports` becomes
     * `mailer.transports.open_telemetry`.
     */
    public static function id(string $decoratedId): string
    {
        return \sprintf('%s.%s', $decoratedId, self::SUFFIX);
    }

    /**
     * Where the decorated service moves to once the decorator takes its place.
     *
     * Named explicitly rather than left to Symfony's `.inner` default, because a
     * decorator that declares its arguments by name has to reference it by id.
     */
    public static function innerId(string $decoratedId): string
    {
        return \sprintf('%s.inner', self::id($decoratedId));
    }

    /**
     * A service the bundle registers beside another without decorating it — a Messenger
     * middleware, say, which is inserted into a chain rather than wrapped around a
     * service: `messenger.bus.default` becomes
     * `messenger.bus.default.open_telemetry_middleware`.
     *
     * @param non-empty-string $role what the service is, in one word
     */
    public static function siblingId(string $baseId, string $role): string
    {
        return \sprintf('%s.%s_%s', $baseId, self::SUFFIX, $role);
    }
}
