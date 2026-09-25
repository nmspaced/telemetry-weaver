<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Api;

/**
 * An immutable snapshot of a span's identity. Holding it does not keep the span alive.
 *
 * @api
 */
final readonly class TraceContext
{
    private const int SAMPLED = 0x01;

    private const int RANDOM = 0x02;

    /** @var non-empty-string */
    public string $traceId;

    /** @var non-empty-string */
    public string $spanId;

    /** @var int<0, 255> */
    public int $traceFlags;

    /**
     * @param string $traceId 32 lowercase hex digits, not all zero
     * @param string $spanId 16 lowercase hex digits, not all zero
     * @param int $traceFlags the W3C flags byte (0..255)
     *
     * @throws \InvalidArgumentException if the identity or flags are invalid
     */
    public function __construct(string $traceId, string $spanId, int $traceFlags)
    {
        if ($traceId === '' || \preg_match('/\A[0-9a-f]{32}\z/', $traceId) !== 1 || $traceId === \str_repeat('0', 32)) {
            throw new \InvalidArgumentException('Trace ID must be 32 lowercase hex digits and not all zero.');
        }

        if ($spanId === '' || \preg_match('/\A[0-9a-f]{16}\z/', $spanId) !== 1 || $spanId === \str_repeat('0', 16)) {
            throw new \InvalidArgumentException('Span ID must be 16 lowercase hex digits and not all zero.');
        }

        if ($traceFlags < 0 || $traceFlags > 255) {
            throw new \InvalidArgumentException('Trace flags must fit in an unsigned byte.');
        }

        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->traceFlags = $traceFlags;
    }

    /**
     * Whether the sampled bit is set. An unsampled context can still be used for correlation.
     */
    public function sampled(): bool
    {
        return ($this->traceFlags & self::SAMPLED) !== 0;
    }

    /**
     * The flags as two lowercase hex digits.
     *
     * @return non-empty-string
     */
    public function traceFlagsHex(): string
    {
        return \sprintf('%02x', $this->traceFlags);
    }

    /**
     * A W3C `traceparent` (version 00) for correlation fields such as SQL comments. For
     * transport headers use the configured propagator instead.
     *
     * @see https://www.w3.org/TR/trace-context-2/#other-flags
     *
     * @return non-empty-string
     */
    public function traceparent(): string
    {
        return \sprintf(
            '00-%s-%s-%02x',
            $this->traceId,
            $this->spanId,
            $this->traceFlags & (self::SAMPLED | self::RANDOM),
        );
    }
}
