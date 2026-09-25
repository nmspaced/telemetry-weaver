<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whether `operation()->baggage()` reaches a downstream service, across the switches.
 *
 * The global `traces.enabled` and `metrics.enabled` decide which signals are recorded, not
 * whether the application's context leaves the process: with both off, the client is
 * still decorated and still propagates. Switching both of the component's own signals off
 * is the one way to opt the component out, and then nothing is added to its requests.
 */
final class HttpClientContextPropagationTest extends ContainerTestCase
{
    /** @var list<string> the headers of the last request the mock transport received */
    private static array $sent = [];

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function switches(): iterable
    {
        foreach ([true, false] as $traces) {
            foreach ([true, false] as $metrics) {
                yield \sprintf('traces %s, metrics %s', $traces ? 'on' : 'off', $metrics ? 'on' : 'off') => [
                    ['traces' => ['enabled' => $traces], 'metrics' => ['enabled' => $metrics]],
                    true,
                ];
            }
        }

        yield 'the component switched off' => [
            ['instrumentation' => ['http_client' => ['traces' => false, 'metrics' => false]]],
            false,
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws \Throwable
     */
    #[Test]
    #[DataProvider('switches')]
    public function baggageReachesTheDownstreamServiceUnlessTheComponentIsOff(array $config, bool $propagated): void
    {
        $container = $this->compile($config, configure: self::withClient(...));
        $client = $container->get('http_client');
        $telemetry = $container->get(Telemetry::class);
        self::assertInstanceOf(HttpClientInterface::class, $client);
        self::assertInstanceOf(Telemetry::class, $telemetry);

        $telemetry
            ->operation('work')
            ->baggage(['tenant' => 'synthetic'])
            ->run(
                /** @throws \Throwable */
                static fn(): string => $client->request('GET', 'https://downstream.invalid/')->getContent(),
            );

        self::assertSame($propagated, \in_array('baggage: tenant=synthetic', self::$sent, true));
    }

    private static function withClient(ContainerBuilder $container): void
    {
        self::$sent = [];
        $container
            ->register('http_client', MockHttpClient::class)
            ->setArgument(
                0,
                /** @param array<array-key, mixed> $options */
                static function (string $_method, string $_url, array $options): MockResponse {
                    /** @var list<string> $headers */
                    $headers = $options['headers'] ?? [];
                    self::$sent = $headers;

                    return new MockResponse('ok');
                },
            )
            ->addTag('http_client.client');
    }
}
