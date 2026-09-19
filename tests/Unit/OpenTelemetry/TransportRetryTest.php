<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpTransportSettings;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingTransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransportFactory::class)]
final class TransportRetryTest extends TestCase
{
    /**
     * OTEL_EXPORTER_OTLP_HEADERS is resolved by the upstream exporter factory and arrives
     * as $headers, so this is the only seam where configured headers can still reach the
     * transport. The configured entries win; a null drops one the variable set.
     */
    #[Test]
    public function theConfiguredHeadersAreMergedOverTheOnesTheCallerPassed(): void
    {
        $delegate = new RecordingTransportFactory();
        $factory = new TransportFactory($delegate, Flushers::openGate(), new OtlpTransportSettings(headers: [
            'x-scope-orgid' => 'tenant-a',
            'x-retained' => 1,
            'x-dropped' => null,
        ]));

        $factory->create('http://collector:4318/v1/traces', 'application/x-protobuf', [
            'x-scope-orgid' => 'from-the-variable',
            'x-dropped' => 'from-the-variable',
            'x-untouched' => 'kept',
        ]);

        self::assertSame(
            [
                'x-scope-orgid' => 'tenant-a',
                'x-untouched' => 'kept',
                'x-retained' => '1',
            ],
            $delegate->argument('headers'),
        );
    }

    /**
     * The SDK's retry loop sleeps in the calling process, so the configured limits have
     * to win over whatever the caller passed — including the interface defaults the
     * OTLP exporter factories leave in place by calling create() with five arguments.
     */
    #[Test]
    public function theConfiguredRetrySettingsReplaceTheOnesTheCallerPassed(): void
    {
        $delegate = new RecordingTransportFactory();
        $factory = new TransportFactory(
            $delegate,
            Flushers::openGate(),
            new OtlpTransportSettings(maxRetries: 0, retryDelay: 250),
        );

        $factory->create('http://collector:4318/v1/traces', 'application/x-protobuf');

        self::assertSame(0, $delegate->argument('maxRetries'));
        self::assertSame(250, $delegate->argument('retryDelay'));
    }

    #[Test]
    public function anExplicitRetryArgumentFromTheCallerIsStillOverridden(): void
    {
        $delegate = new RecordingTransportFactory();
        $factory = new TransportFactory(
            $delegate,
            Flushers::openGate(),
            new OtlpTransportSettings(maxRetries: 1, retryDelay: 50),
        );

        $factory->create('http://collector:4318/v1/traces', 'application/x-protobuf', retryDelay: 5000, maxRetries: 9);

        self::assertSame(1, $delegate->argument('maxRetries'));
        self::assertSame(50, $delegate->argument('retryDelay'));
    }

    /**
     * Everything other than the two retry arguments has to arrive untouched and in the
     * right position — the TLS triple in particular, where an off-by-one would silently
     * send the client certificate as the CA bundle.
     */
    #[Test]
    public function everyOtherArgumentIsForwardedInItsOwnPosition(): void
    {
        $delegate = new RecordingTransportFactory();
        $factory = new TransportFactory($delegate, Flushers::openGate());

        $factory->create(
            'http://collector:4318/v1/metrics',
            'application/json',
            ['x-api-key' => 'secret'],
            'gzip',
            2.5,
            100,
            3,
            '/etc/ssl/ca.pem',
            '/etc/ssl/client.pem',
            '/etc/ssl/client.key',
        );

        self::assertSame(
            [
                'endpoint' => 'http://collector:4318/v1/metrics',
                'contentType' => 'application/json',
                'headers' => ['x-api-key' => 'secret'],
                'compression' => 'gzip',
                'timeout' => 2.5,
                'retryDelay' => 100,
                'maxRetries' => 0,
                'cacert' => '/etc/ssl/ca.pem',
                'cert' => '/etc/ssl/client.pem',
                'key' => '/etc/ssl/client.key',
            ],
            $delegate->call(),
        );
    }

    #[Test]
    public function retryingIsOffUnlessItIsAskedFor(): void
    {
        $delegate = new RecordingTransportFactory();
        new TransportFactory($delegate, Flushers::openGate())->create(
            'http://collector:4318/v1/logs',
            'application/x-protobuf',
        );

        self::assertSame(
            0,
            $delegate->argument('maxRetries'),
            'the default must not block the application on a dead collector',
        );
    }
}
