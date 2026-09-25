<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleFlushSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Console\ConsoleTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Client\TraceableHttpClient;
use Nmspaced\TelemetryWeaver\Instrumentation\Mailer\TraceableMailTransport;
use Nmspaced\TelemetryWeaver\Instrumentation\Scheduler\SchedulerTelemetrySubscriber;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FrameworkInstrumentationRegistrationTest extends ContainerTestCase
{
    private static function services(ContainerBuilder $container): void
    {
        $container->register('mailer.transports', NullTransport::class);
        $container
            ->register('http_client.transport', MockHttpClient::class)
            ->setArguments([[
                new Definition(MockResponse::class, ['retry', ['http_code' => 503]]),
                new Definition(MockResponse::class, ['ok']),
                new Definition(MockResponse::class, ['retry', ['http_code' => 503]]),
                new Definition(MockResponse::class, ['scoped']),
            ]]);
        foreach (['http_client', 'api.client'] as $id) {
            $container
                ->register($id, HttpClientInterface::class)
                ->setFactory('current')
                ->setArguments([[new Reference('http_client.transport')]])
                ->addTag('http_client.client');
            $container
                ->register($id . '.retry', RetryableHttpClient::class)
                ->setDecoratedService($id, null, 25)
                ->setArguments([
                    new Reference($id . '.retry.inner'),
                    new Definition(GenericRetryStrategy::class, [GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, 0]),
                    1,
                ]);
        }

        $container
            ->register('api.client.scoping', ScopingHttpClient::class)
            ->setFactory([ScopingHttpClient::class, 'forBaseUri'])
            ->setDecoratedService('api.client', null, 15)
            ->setArguments([
                new Reference('api.client.scoping.inner'),
                'https://api.example.org/v1/',
                ['headers' => ['X-Scoped' => 'preserved']],
            ]);
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function compiledClientsResolveScopesAndRetriesWithoutDuplicateSpans(): void
    {
        $exporter = new InMemoryExporter();
        $container = $this->compile(configure: static function (ContainerBuilder $container) use ($exporter): void {
            self::services($container);
            $container->register(SpanExporterInterface::class)->setSynthetic(true);
            $container->set(SpanExporterInterface::class, $exporter);
        });
        self::assertInstanceOf(TraceableHttpClient::class, $container->get('http_client'));
        $httpClient = self::httpClient($container, 'http_client');
        $apiClient = self::httpClient($container, 'api.client');
        self::assertSame('ok', $httpClient->request('GET', 'https://example.org')->getContent());
        self::assertSame('scoped', $apiClient->request('GET', 'users')->getContent());
        $tracers = $container->get(TracerProviderInterface::class);
        $tracers->forceFlush();
        /** @var list<SpanDataInterface> $spans */
        $spans = $exporter->getSpans();
        self::assertCount(
            2,
            $spans,
            'One operation includes all retry attempts; scoped clients do not pass through the default decorated client.',
        );
        $scopedSpan = $spans[1] ?? Assert::fail('missing scoped-client span');
        self::assertSame('api.example.org', $scopedSpan->getAttributes()->get('server.address'));
        self::assertSame(200, $scopedSpan->getAttributes()->get('http.response.status_code'));
        self::assertInstanceOf(TraceableMailTransport::class, $container->get('mailer.transports'));
        self::assertInstanceOf(ConsoleTelemetrySubscriber::class, $container->get(ConsoleTelemetrySubscriber::class));
        self::assertInstanceOf(
            SchedulerTelemetrySubscriber::class,
            $container->get(SchedulerTelemetrySubscriber::class),
        );
    }

    /** @throws \Throwable */
    private static function httpClient(ContainerBuilder $container, string $id): HttpClientInterface
    {
        $service = $container->get($id);
        if (!$service instanceof HttpClientInterface) {
            Assert::fail('service "' . $id . '" is not an HttpClientInterface');
        }

        return $service;
    }

    /** @return iterable<int, array{bool, bool}> */
    public static function switches(): iterable
    {
        foreach ([false, true] as $traces) {
            foreach ([false, true] as $metrics) {
                yield [$traces, $metrics];
            }
        }
    }

    /**
     * Either signal keeps an adapter that only records. The HTTP client also carries trace
     * context and baggage to the next service, which the global signal switches do not
     * govern, so it stays decorated with both of them off.
     *
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('switches')]
    public function eitherSignalKeepsTheAdapters(bool $traces, bool $metrics): void
    {
        $container = $this->compile(
            ['traces' => ['enabled' => $traces], 'metrics' => ['enabled' => $metrics]],
            configure: self::services(...),
        );
        self::assertInstanceOf(TraceableHttpClient::class, $container->get('http_client'));
        self::assertSame($traces || $metrics, $container->get('mailer.transports') instanceof TraceableMailTransport);
        self::assertSame($traces || $metrics, $container->has(SchedulerTelemetrySubscriber::class));
        self::assertSame($traces || $metrics, $container->has(ConsoleTelemetrySubscriber::class));
        self::assertTrue($container->has(ConsoleFlushSubscriber::class));
    }

    /**
     * @throws \Throwable
     */
    #[Test]
    public function disablingTheBundleRegistersNoAdaptersOrFlushSubscribers(): void
    {
        $container = $this->compile(['enabled' => false], configure: self::services(...));
        self::assertNotInstanceOf(TraceableHttpClient::class, $container->get('http_client'));
        self::assertInstanceOf(NullTransport::class, $container->get('mailer.transports'));
        self::assertFalse($container->has(ConsoleTelemetrySubscriber::class));
        self::assertFalse($container->has(ConsoleFlushSubscriber::class));
        self::assertFalse($container->has(SchedulerTelemetrySubscriber::class));
    }
}
