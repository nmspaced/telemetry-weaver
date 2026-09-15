<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http;

/**
 * The method allow-list from OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS.
 *
 * A service so the environment is read once per process rather than on every
 * request: the list cannot change while the process runs, and the read sat on
 * the hot path.
 *
 * Read here rather than injected as a container parameter on purpose. A
 * parameter is resolved when the container is compiled and the compiled
 * container is cached, so the value would be frozen from build time instead
 * of run time.
 */
final readonly class KnownHttpMethods
{
    /** @var non-empty-list<non-empty-string> */
    private const array DEFAULT = [
        'CONNECT',
        'DELETE',
        'GET',
        'HEAD',
        'OPTIONS',
        'PATCH',
        'POST',
        'PUT',
        'TRACE',
        'QUERY',
    ];

    /**
     * Flipped: contains() is called twice per request and a hash lookup beats
     * a linear scan over a list that is only ever asked about membership.
     *
     * @var non-empty-array<non-empty-string, true>
     */
    private array $methods;

    public function __construct()
    {
        $this->methods = \array_fill_keys(self::parse(self::configured()) ?? self::DEFAULT, true);
    }

    public function contains(string $method): bool
    {
        return $this->methods[$method] ?? false;
    }

    private static function configured(): ?string
    {
        $configured = \getenv('OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS');

        if ($configured !== false) {
            return $configured;
        }

        // Symfony Dotenv does not enable putenv by default.
        /** @var mixed $fallback */
        $fallback = $_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS'] ?? null;

        return \is_string($fallback) ? $fallback : null;
    }

    /**
     * An empty or blank setting falls back to the defaults rather than
     * producing an empty list. "Unset it by blanking it" is how the variable
     * gets written in practice, and an empty list would silently rename every
     * span to HTTP and report every method as _OTHER.
     *
     * @return non-empty-list<non-empty-string>|null
     */
    private static function parse(?string $configured): ?array
    {
        if ($configured === null) {
            return null;
        }

        $methods = [];

        foreach (\explode(',', $configured) as $candidate) {
            $method = \trim($candidate);

            if ($method !== '') {
                $methods[] = $method;
            }
        }

        return $methods === [] ? null : $methods;
    }
}
