<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SecurityInstrumentationCompilerPass;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributes;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributesSubscriber;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/** Container wiring for recording the authenticated user. */
#[CoversClass(SecurityInstrumentationCompilerPass::class)]
final class SecurityInstrumentationContainerTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function theUserSubscriberIsAbsentUntilAKeyAsksForIt(): void
    {
        $container = $this->compile(configure: static function (ContainerBuilder $container): void {
            $container->register('security.untracked_token_storage', TokenStorage::class);
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
            $container->register('security.untracked_token_storage', TokenStorage::class)->setPublic(true);
        });

        $tokens = $container->get('security.untracked_token_storage');
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

    /** @throws \Throwable */
    #[Test]
    public function withoutATokenStorageNothingIsRegistered(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['record_user_id' => true, 'record_user_roles' => true]],
        ]);

        self::assertFalse($container->hasDefinition(UserAttributesSubscriber::class));
    }

    /** @throws \Throwable */
    #[Test]
    public function theUserAttributesReadTheUntrackedTokenStorage(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['record_user_roles' => true]],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('security.untracked_token_storage', TokenStorage::class);
            $container->register('security.token_storage', TokenStorage::class);
        });

        self::assertSame(
            'security.untracked_token_storage',
            (string) $container->getDefinition(UserAttributes::class)->getArgument(0),
        );
    }

    /** @throws \Throwable */
    #[Test]
    public function theTrackedStorageAloneIsNotEnoughToRegister(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['record_user_roles' => true]],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('security.token_storage', TokenStorage::class);
        });

        self::assertFalse($container->hasDefinition(UserAttributesSubscriber::class));
    }
}
