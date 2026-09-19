<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\BudgetedOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\CustomOtlpTransports;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\OtlpProtocol;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\TransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingTransportFactory;
use Nmspaced\TelemetryWeaver\Tests\Support\Flushers;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BudgetedOtlpTransports::class)]
#[CoversClass(CustomOtlpTransports::class)]
#[CoversClass(OtlpProtocol::class)]
final class OtlpTransportsTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        unset($_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL], $_SERVER[Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL]);
    }

    #[Test]
    public function theBundlesTransportsPutEveryProtocolBehindItsOwnTransportFactory(): void
    {
        self::assertInstanceOf(
            TransportFactory::class,
            new BudgetedOtlpTransports(Flushers::openGate())->forProtocol('http/protobuf'),
        );
    }

    /**
     * `http/protobuf`, `http/json` and `http/ndjson` are one family, as in the SDK registry: the
     * content type the exporter passes is what tells them apart, so one factory can serve all three.
     */
    #[Test]
    public function anApplicationsTransportFactoryServesEveryProtocolOfItsFamily(): void
    {
        $http = new RecordingTransportFactory();
        $transports = new CustomOtlpTransports([
            OtlpProtocol::HTTP => $http,
        ], new BudgetedOtlpTransports(Flushers::openGate()));

        self::assertSame($http, $transports->forProtocol('http/protobuf'));
        self::assertSame($http, $transports->forProtocol('http/json'));
        self::assertSame($http, $transports->forProtocol('http/ndjson'));
    }

    /**
     * gRPC and `http/protobuf` share a content type, so a factory handed both could only guess
     * which one a call is for. A family the application did not replace keeps the bundle's transport.
     */
    #[Test]
    public function aFamilyWithoutAnApplicationsFactoryKeepsTheBundlesTransport(): void
    {
        $grpc = new RecordingTransportFactory();
        $transports = new CustomOtlpTransports([
            OtlpProtocol::GRPC => $grpc,
        ], new BudgetedOtlpTransports(Flushers::openGate()));

        self::assertSame($grpc, $transports->forProtocol('grpc'));
        self::assertInstanceOf(TransportFactory::class, $transports->forProtocol('http/protobuf'));
    }

    #[Test]
    public function aSignalSpecificProtocolWinsOverTheGenericOne(): void
    {
        $_SERVER[Variables::OTEL_EXPORTER_OTLP_PROTOCOL] = 'grpc';
        $_SERVER[Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL] = 'http/json';

        self::assertSame('http/json', OtlpProtocol::of(Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL));
        self::assertSame('grpc', OtlpProtocol::of(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL));
    }
}
