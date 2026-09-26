<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Adapter;

use OpenTelemetry\Context\ScopeInterface;

/**
 * The context activations the package holds, keyed by scope. A confining owner finds its inner
 * owners here; at `exit` or a fatal error, where `finally` blocks are skipped, the remaining
 * ones are detached. Holds owners weakly and never ends spans or exports.
 *
 * @internal
 */
final class OwnedActivations
{
    /** @var \WeakMap<ScopeInterface, \WeakReference<OwnedSpan>>|null */
    private static ?\WeakMap $owners = null;

    public static function register(ScopeInterface $activation, OwnedSpan $owner): void
    {
        if (self::$owners === null) {
            self::$owners = new \WeakMap();
            \register_shutdown_function(self::detach(...));
        }

        self::$owners[$activation] = \WeakReference::create($owner);
    }

    public static function forget(ScopeInterface $activation): void
    {
        unset(self::$owners[$activation]);
    }

    public static function ownerOf(ScopeInterface $scope): ?OwnedSpan
    {
        return (self::$owners[$scope] ?? null)?->get();
    }

    private static function detach(): void
    {
        $owners = [];
        foreach (self::$owners ?? [] as $owner) {
            $owners[] = $owner;
        }

        foreach (\array_reverse($owners) as $owner) {
            $owner->get()?->detach();
        }
    }
}
