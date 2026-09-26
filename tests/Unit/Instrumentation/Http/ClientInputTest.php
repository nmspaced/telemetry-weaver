<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Api\RunningOperation;
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\OutgoingRequest;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\RequestPropagation;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\ResponseMetadata;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Nmspaced\TelemetryWeaver\Internal\Propagation\Propagation;
use Nmspaced\TelemetryWeaver\Tests\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** What the HTTP client instrumentation does with requests and responses it cannot describe. */
#[CoversClass(OutgoingRequest::class)]
#[CoversClass(RequestPropagation::class)]
#[CoversClass(ResponseMetadata::class)]
final class ClientInputTest extends TestCase
{
    private RecordingLogger $logger;

    private InstrumentationFailureReporter $reporter;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->reporter = new InstrumentationFailureReporter($this->logger);
    }

    /** @return iterable<string, array{string}> */
    public static function undescribableUrls(): iterable
    {
        yield 'malformed' => ['http:///orders'];
        yield 'relative without a base' => ['/orders'];
        yield 'not http' => ['ftp://files.example.org/report.csv'];
    }

    #[Test]
    #[DataProvider('undescribableUrls')]
    public function aRequestWithoutAnHttpHostIsNotDescribed(string $url): void
    {
        self::assertNull(OutgoingRequest::from('GET', $url));
    }

    /** @throws \Throwable */
    #[Test]
    public function headersInAShapeItDoesNotKnowAreLeftAlone(): void
    {
        $propagation = $this->createStub(Propagation::class);
        $propagation->method('injectCurrent')->willReturn(['traceparent' => '00-abc-def-01']);

        $options = new RequestPropagation($propagation, $this->reporter)->inject(['headers' => 'X-Custom: 1']);

        self::assertSame(['headers' => 'X-Custom: 1'], $options);
        self::assertSame(0, $this->reporter->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function aFailingPropagationSendsTheRequestAsItWas(): void
    {
        $propagation = $this->createStub(Propagation::class);
        $propagation->method('injectCurrent')->willReturn(['traceparent' => '00-abc-def-01']);
        $propagation->method('fields')->willThrowException(new \RuntimeException('propagator is gone'));
        $options = ['headers' => ['X-Custom' => '1']];

        self::assertSame($options, new RequestPropagation($propagation, $this->reporter)->inject($options));
        self::assertStringContainsString('HTTP context injection failed', $this->logger->messageAt(0));
    }

    /** @return iterable<string, array{string}> */
    public static function urlsWithoutAnOrigin(): iterable
    {
        yield 'malformed' => ['http:///orders'];
        yield 'no scheme or host' => ['orders/7'];
    }

    /** @throws \Throwable */
    #[Test]
    #[DataProvider('urlsWithoutAnOrigin')]
    public function aResponseUrlWithoutAnOriginIsNotRecorded(string $url): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getInfo')->willReturn($url);

        new ResponseMetadata($this->reporter)->describe($this->operationRecordingNothing(), $response);

        self::assertSame(0, $this->reporter->total());
    }

    /** @throws \Throwable */
    #[Test]
    public function anUnreadableResponseUrlIsReportedNotThrown(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getInfo')->willThrowException(new \RuntimeException('response is gone'));

        new ResponseMetadata($this->reporter)->describe($this->operationRecordingNothing(), $response);

        self::assertStringContainsString('HTTP URL extraction failed', $this->logger->messageAt(0));
    }

    /** @throws \Throwable */
    private function operationRecordingNothing(): RunningOperation
    {
        $span = $this->createMock(Span::class);
        $span->expects(self::never())->method('attribute');
        $operation = $this->createStub(RunningOperation::class);
        $operation->method('span')->willReturn($span);

        return $operation;
    }
}
