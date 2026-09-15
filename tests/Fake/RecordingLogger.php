<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Psr\Log\AbstractLogger;

/**
 * Logger that records entries so a test can read them back.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return \array_column($this->records, 'message');
    }

    public function messageAt(int $index): string
    {
        return $this->records[$index]['message'] ?? '';
    }

    public function count(): int
    {
        return \count($this->records);
    }

    /** @return array<array-key, mixed> */
    public function contextAt(int $index): array
    {
        return $this->records[$index]['context'] ?? [];
    }
}
