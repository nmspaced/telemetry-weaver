<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The request method as sent on the wire, before Symfony's method override. Unknown
 * methods become `_OTHER`, with the original kept separately.
 */
final readonly class HttpMethod
{
    private const string OTHER = '_OTHER';

    /**
     * @param non-empty-string $value normalized, or _OTHER
     * @param string $original as it arrived; empty when unreadable
     */
    private function __construct(
        public string $value,
        public string $original,
    ) {}

    public static function from(Request $request, KnownHttpMethods $known = new KnownHttpMethods()): self
    {
        /** @var mixed $wire */
        $wire = $request->server->get('REQUEST_METHOD', 'GET');

        return self::fromString(\is_string($wire) ? $wire : '', $known);
    }

    public static function fromString(string $original, KnownHttpMethods $known = new KnownHttpMethods()): self
    {
        $method = $known->contains($original) ? $original : \strtoupper($original);

        return new self($method !== '' && $known->contains($method) ? $method : self::OTHER, $original);
    }

    /** Whether a non-empty original spelling differs from the normalized value. */
    public function hasDistinctOriginal(): bool
    {
        return $this->original !== '' && $this->original !== $this->value;
    }

    /** @return non-empty-string */
    public function spanName(): string
    {
        return $this->value === self::OTHER ? 'HTTP' : $this->value;
    }
}
