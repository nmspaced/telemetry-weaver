<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SecurityInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\HttpServerMetricsSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Metrics\RequestMeasurementRegistry;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributes;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributesSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\HttpServerTracingSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\TelemetryWeaverBundle;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(TelemetryWeaverBundle::class)]
#[CoversClass(SecurityInstrumentationCompilerPass::class)]
final class HttpContainerTest extends ContainerTestCase
{
    /**
     * Identity capture costs the container nothing until a key asks for it. Both keys are
     * off by default, so the common case has no subscriber to run and no token storage to
     * resolve on every request.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theUserSubscriberIsAbsentUntilAKeyAsksForIt(): void
    {
        $container = $this->compile(configure: static function (ContainerBuilder $container): void {
            $container->register('security.token_storage', TokenStorage::class);
        });

        self::assertFalse($container->hasDefinition(UserAttributesSubscriber::class));
        self::assertFalse($container->hasDefinition(UserAttributes::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function askingForRolesAloneRegistersTheSubscriberWithoutTheIdentifier(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['record_user_roles' => true]],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('security.token_storage', TokenStorage::class)->setPublic(true);
        });

        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorage::class, $tokens);
        $tokens->setToken(
            new UsernamePasswordToken(
                new InMemoryUser('alice', null, ['ROLE_ADMIN']),
                'main',
                [
                    'ROLE_ADMIN',
                ],
            ),
        );

        $users = $container->get(UserAttributes::class);
        self::assertInstanceOf(UserAttributes::class, $users);
        self::assertInstanceOf(UserAttributesSubscriber::class, $container->get(UserAttributesSubscriber::class));
        self::assertSame(['user.roles' => ['ROLE_ADMIN']], $users->current(), 'the identifier stays off on its own');
    }

    /**
     * Without a firewall there is nothing to read, and a service referencing
     * `security.token_storage` would fail the compile.
     *
     * @throws \Throwable
     */
    #[Test]
    public function withoutATokenStorageNothingIsRegistered(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['record_user_id' => true, 'record_user_roles' => true]],
        ]);

        self::assertFalse($container->hasDefinition(UserAttributesSubscriber::class));
    }

    /**
     * Instantiation, not just compilation: argument order only fails when the
     * constructor actually runs.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theHttpSubscriberIsInstantiable(): void
    {
        $container = $this->compile();

        self::assertInstanceOf(HttpServerTracingSubscriber::class, $container->get(HttpServerTracingSubscriber::class));
        self::assertInstanceOf(RequestTraceRegistry::class, $container->get(RequestTraceRegistry::class));
        self::assertInstanceOf(HttpServerMetricsSubscriber::class, $container->get(HttpServerMetricsSubscriber::class));
        self::assertInstanceOf(RequestMeasurementRegistry::class, $container->get(RequestMeasurementRegistry::class));
    }

    /**
     * The package instruments through the dispatcher. Decorating http_kernel
     * would put a second owner on the request lifecycle and hide the
     * instrumentation from debug:event-dispatcher.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theKernelIsNotDecorated(): void
    {
        $container = $this->compile();

        self::assertInstanceOf(HttpKernel::class, $container->get('http_kernel'));
    }

    /** @throws \Throwable */
    #[Test]
    public function metricsWorkWithoutTracing(): void
    {
        $container = $this->compile([
            'traces' => ['enabled' => false],
            'instrumentation' => ['http_server' => ['metrics' => ['excluded_paths' => ['/metrics-health']]]],
        ]);
        self::assertInstanceOf(HttpServerTracingSubscriber::class, $container->get(HttpServerTracingSubscriber::class));
        self::assertInstanceOf(HttpServerMetricsSubscriber::class, $container->get(HttpServerMetricsSubscriber::class));
        self::assertSame(
            ['/metrics-health'],
            $container->getParameter('open_telemetry.instrumentation.http_server.metrics.excluded_paths'),
        );
        self::assertTrue($container->getDefinition(RequestMeasurementRegistry::class)->hasTag('kernel.reset'));
    }

    /** @throws \Throwable */
    #[Test]
    public function disablingMetricsKeepsBothLifecycleSubscribers(): void
    {
        $container = $this->compile(['metrics' => ['enabled' => false]]);
        self::assertInstanceOf(HttpServerTracingSubscriber::class, $container->get(HttpServerTracingSubscriber::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function httpMetricsCanBeNoopSeparately(): void
    {
        $container = $this->compile(['instrumentation' => ['http_server' => ['metrics' => false]]]);
        self::assertInstanceOf(HttpServerMetricsSubscriber::class, $container->get(HttpServerMetricsSubscriber::class));
        self::assertInstanceOf(NoopMeter::class, $container->get('open_telemetry.http_server.meter'));
        self::assertTrue($container->has(HttpServerTracingSubscriber::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function globalDisableRemovesBothSubscribers(): void
    {
        $container = $this->compile(['enabled' => false]);
        self::assertFalse($container->has(HttpServerMetricsSubscriber::class));
        self::assertFalse($container->has(HttpServerTracingSubscriber::class));
    }
}
