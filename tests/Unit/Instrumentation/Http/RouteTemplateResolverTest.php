<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\NullRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RequestRouteTemplateResolver;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(RequestRouteTemplateResolver::class)]
#[CoversClass(NullRouteTemplateProvider::class)]
final class RouteTemplateResolverTest extends TestCase
{
    #[Test]
    public function resolvesTemplateForRoutedRequest(): void
    {
        $request = self::request();
        $request->attributes->set('_route', 'app_orders_show');

        self::assertSame('/orders/{id}', $this->resolver()->resolve($request));
    }

    #[Test]
    public function unroutedRequestResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(self::request()));
    }

    #[Test]
    public function nonStringRouteAttributeResolvesToNull(): void
    {
        $request = self::request();
        $request->attributes->set('_route', ['app_orders_show']);

        self::assertNull($this->resolver()->resolve($request));
    }

    #[Test]
    public function emptyRouteAttributeResolvesToNull(): void
    {
        $request = self::request();
        $request->attributes->set('_route', '');

        self::assertNull($this->resolver()->resolve($request));
    }

    #[Test]
    public function emptySourceResolvesToNull(): void
    {
        $request = self::request();
        $request->attributes->set('_route', 'app_orders_show');

        self::assertNull(new RequestRouteTemplateResolver(new NullRouteTemplateProvider())->resolve($request));
    }

    private static function request(): Request
    {
        return new Request(server: ['REQUEST_URI' => '/orders/17', 'REQUEST_METHOD' => 'GET']);
    }

    private function resolver(): RequestRouteTemplateResolver
    {
        return new RequestRouteTemplateResolver(new class implements RouteTemplateProvider {
            #[\Override]
            public function resolve(string $routeName): ?string
            {
                return $routeName === 'app_orders_show' ? '/orders/{id}' : null;
            }
        });
    }
}
