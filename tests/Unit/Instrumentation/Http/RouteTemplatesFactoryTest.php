<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\NullRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\PhpFileRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouterRouteTemplateProvider;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Routing\RouteTemplateProviderFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(RouteTemplateProviderFactory::class)]
#[CoversClass(RouterRouteTemplateProvider::class)]
final class RouteTemplatesFactoryTest extends TestCase
{
    #[Test]
    public function productionAlwaysReadsTheDump(): void
    {
        $templates = RouteTemplateProviderFactory::create(\sys_get_temp_dir(), $this->router(), false);

        self::assertInstanceOf(PhpFileRouteTemplateProvider::class, $templates);
    }

    #[Test]
    public function developmentFallsBackToRouterWhenDumpIsStale(): void
    {
        $templates = RouteTemplateProviderFactory::create(\sys_get_temp_dir(), $this->router(), true);

        self::assertInstanceOf(RouterRouteTemplateProvider::class, $templates);
        self::assertSame('/orders/{id}', $templates->resolve('app_orders_show'));
    }

    #[Test]
    public function withoutRouterAndDumpNothingResolves(): void
    {
        $templates = RouteTemplateProviderFactory::create(\sys_get_temp_dir(), null, true);

        self::assertInstanceOf(NullRouteTemplateProvider::class, $templates);
        self::assertNull($templates->resolve('app_orders_show'));
    }

    private function router(): RouterInterface
    {
        return new class implements RouterInterface {
            #[\Override]
            public function getRouteCollection(): RouteCollection
            {
                $collection = new RouteCollection();
                $collection->add('app_orders_show', new Route('/orders/{id}'));

                return $collection;
            }

            /** @param array<array-key, mixed> $parameters */
            #[\Override]
            public function generate(
                string $name,
                array $parameters = [],
                int $referenceType = self::ABSOLUTE_PATH,
            ): string {
                return '/';
            }

            /** @return array<string, mixed> */
            #[\Override]
            public function match(string $pathinfo): array
            {
                return [];
            }

            #[\Override]
            public function setContext(RequestContext $context): void {}

            #[\Override]
            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
    }
}
