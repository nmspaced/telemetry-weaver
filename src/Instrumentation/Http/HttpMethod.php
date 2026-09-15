<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The request method as semconv wants it reported.
 *
 * Read from the wire, before Symfony's application-level method override:
 * http.request.method describes what the client actually sent.
 *
 * Anything outside the known list becomes _OTHER, so the attribute cannot
 * become an unbounded label; the original is preserved separately when the
 * two differ.
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

    /**
     * Whether the original spelling is worth reporting alongside the
     * normalized one. An unreadable REQUEST_METHOD has no original to report,
     * and writing an empty attribute would say less than writing none.
     */
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
