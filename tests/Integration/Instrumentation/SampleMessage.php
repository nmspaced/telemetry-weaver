<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

/** @internal a message with no behaviour, used to exercise the Messenger instrumentation */
final readonly class SampleMessage
{
    public function __construct(
        public string $name,
    ) {}
}
