<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** Proof that the real transport ran and that its return value is what the caller got back. */
final readonly class SentMarkerStamp implements StampInterface {}
