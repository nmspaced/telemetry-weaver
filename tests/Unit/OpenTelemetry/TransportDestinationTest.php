<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Runtime\ExportGate;
use Nmspaced\TelemetryWeaver\Internal\Runtime\FlushBudget;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\FrozenClock;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingTransportFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Which collector a transport's sends are charged to, and how long a boundary send may take. */
#[CoversClass(TransportFactory::class)]
final class TransportDestinationTest extends TestCase
{
    #[Test]
    public function endpointsOfOneCollectorShareOneDestination(): void
    {
        $budget = new FlushBudget(1000, new FrozenClock());
        $factory = new TransportFactory(new RecordingTransportFactory(), ExportGate::forBudget($budget));

        $factory->create('https://user:secret@Collector/v1/traces', 'application/x-protobuf');
        $factory->create('https://collector:443/v1/metrics', 'application/x-protobuf');
        $factory->create('unix:///run/otel.sock', 'application/x-protobuf');
        $budget->begin();

        self::assertEqualsWithDelta(
            0.5,
            $budget->allowance('https://collector:443')?->seconds() ?? 0.0,
            1e-6,
            'two collectors split the budget, not three endpoints',
        );
        self::assertNotNull($budget->allowance('unix:///run/otel.sock'), 'an endpoint without a host is its own key');
    }

    /** @throws \Throwable */
    #[Test]
    public function aTransportWithoutATimeoutGetsWhateverIsLeftOfTheBudget(): void
    {
        $budget = new FlushBudget(1000, new FrozenClock());
        $delegate = new RecordingTransportFactory();
        $transport = new TransportFactory($delegate, ExportGate::forBudget($budget))
            ->create('http://localhost:4318/v1/traces', 'application/json', timeout: 0);

        $budget->begin();
        $transport->send('batch')->await();

        self::assertSame(1.0, $delegate->argument('timeout', 1));
    }
}
