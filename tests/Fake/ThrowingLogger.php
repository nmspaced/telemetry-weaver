<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Psr\Log\AbstractLogger;

/**
 * Logger whose every call throws — a full disk, a closed stream, a handler that
 * itself exports over a network that is down.
 */
final class ThrowingLogger extends AbstractLogger
{
    public int $calls = 0;

    /**
     * @param array<array-key, mixed> $context
     *
     * @throws \RuntimeException always
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): never
    {
        ++$this->calls;

        throw new \RuntimeException('logging is down');
    }
}
