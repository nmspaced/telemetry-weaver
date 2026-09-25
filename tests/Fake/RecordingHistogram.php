<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\Context\ContextInterface;

/** Captures the values a `DurationTimer` records. */
final class RecordingHistogram implements HistogramInterface
{
    /** @var list<array{float|int, iterable<string, mixed>}> */
    public array $records = [];

    /**
     * The context of each recording, aligned with `$records`.
     *
     * @var list<ContextInterface|false|null>
     */
    public array $contexts = [];

    #[\Override]
    public function isEnabled(): bool
    {
        return true;
    }

    #[\Override]
    public function record(
        float|int $amount,
        iterable $attributes = [],
        ContextInterface|false|null $context = null,
    ): void {
        $this->records[] = [$amount, $attributes];
        $this->contexts[] = $context;
    }
}
