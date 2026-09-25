<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;

/**
 * What one batch processor's queue holds, including a batch being exported, so a boundary can export a full
 * batch before the schedule delay, as the SDK would from `onEnd()`. It is also where the batch
 * processor's sizes come from, so both see the same limits.
 *
 * The defaults describe a queue nobody tracks: it never holds a full batch.
 *
 * @internal
 */
final class ExportBacklog
{
    private int $queued = 0;

    private int $dropped = 0;

    private bool $closed = false;

    public function __construct(
        public readonly int $batchSize = \PHP_INT_MAX,
        public readonly int $capacity = \PHP_INT_MAX,
    ) {}

    public static function spans(): self
    {
        return new self(
            Configuration::getInt(Variables::OTEL_BSP_MAX_EXPORT_BATCH_SIZE, Defaults::OTEL_BSP_MAX_EXPORT_BATCH_SIZE),
            Configuration::getInt(Variables::OTEL_BSP_MAX_QUEUE_SIZE, Defaults::OTEL_BSP_MAX_QUEUE_SIZE),
        );
    }

    public static function logRecords(): self
    {
        return new self(
            Configuration::getInt(
                Variables::OTEL_BLRP_MAX_EXPORT_BATCH_SIZE,
                Defaults::OTEL_BLRP_MAX_EXPORT_BATCH_SIZE,
            ),
            Configuration::getInt(Variables::OTEL_BLRP_MAX_QUEUE_SIZE, Defaults::OTEL_BLRP_MAX_QUEUE_SIZE),
        );
    }

    /** A record reached the processor; past capacity the processor drops it. */
    public function added(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->queued >= $this->capacity) {
            ++$this->dropped;

            return;
        }

        ++$this->queued;
    }

    /** The SDK releases a batch after export finishes, including failed exports. */
    public function completed(int $count): void
    {
        $this->queued -= $count;
    }

    /** Shutdown stops accepting records before it exports the remaining batches. */
    public function close(): void
    {
        $this->closed = true;
    }

    public function holdsFullBatch(): bool
    {
        return $this->queued >= $this->batchSize;
    }

    /** Records dropped since the last call. */
    public function takeDropped(): int
    {
        $dropped = $this->dropped;
        $this->dropped = 0;

        return $dropped;
    }
}
