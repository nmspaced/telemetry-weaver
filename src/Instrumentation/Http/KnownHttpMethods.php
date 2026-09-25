<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http;

/**
 * The method allow-list from `OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS`, read once per process
 * at runtime (a container parameter would freeze the build-time value).
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

    /** @var non-empty-array<non-empty-string, true> */
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

        /** @var mixed $fallback */
        $fallback = $_SERVER['OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS'] ?? null;

        if (!\is_string($fallback)) {
            return null;
        }

        return $fallback;
    }

    /**
     * A blank setting means the defaults, not an empty list.
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

        if ($methods === []) {
            return null;
        }

        return $methods;
    }
}
