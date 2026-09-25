<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

/**
 * Detaches scopes still active at `exit` or a fatal error, where `finally` blocks are skipped.
 * Holds owners weakly and never ends spans or exports.
 *
 * @internal
 */
final class ShutdownScopeCleanup
{
    /** @var \WeakMap<OwnedSpan, null>|null */
    private static ?\WeakMap $owners = null;

    public static function register(OwnedSpan $owner): void
    {
        if (self::$owners === null) {
            self::$owners = new \WeakMap();
            \register_shutdown_function(self::detach(...));
        }

        self::$owners[$owner] = null;
    }

    public static function forget(OwnedSpan $owner): void
    {
        unset(self::$owners[$owner]);
    }

    private static function detach(): void
    {
        $owners = [];
        foreach (self::$owners ?? [] as $owner => $_value) {
            $owners[] = $owner;
        }

        foreach (\array_reverse($owners) as $owner) {
            $owner->detach();
        }
    }
}
