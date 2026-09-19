<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

/**
 * A last resort for exit/fatal shutdown, where PHP skips finally blocks.
 *
 * One callback per process, with weak keys only for currently activated owners:
 * neither the callback nor the registry keeps an operation or its providers alive.
 * Normal detach removes the key immediately. This never ends spans or exports data;
 * an interrupted operation has no measured outcome and shutdown must not start I/O.
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

        // Snapshot before mutation, innermost first. Never unwind scopes belonging
        // to somebody else by walking the global context storage.
        foreach (\array_reverse($owners) as $owner) {
            $owner->detach();
        }
    }
}
