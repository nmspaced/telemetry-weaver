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

/**
 * Wiring for the one capture that reaches outside telemetry: who is logged in.
 *
 * Two questions, and the second is the one that bites. Is anything registered at all — both
 * keys are off by default, so the answer should be no. And which token storage is read — the
 * tracked one would make every response uncacheable behind a lazy firewall, which is
 * telemetry changing what the application sends.
 */
#[CoversClass(SecurityInstrumentationCompilerPass::class)]
final class SecurityInstrumentationContainerTest extends ContainerTestCase
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

    /**
     * Without a firewall there is nothing to read, and a service referencing the token
     * storage would fail the compile.
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
     * `security.token_storage` is a `UsageTrackingTokenStorage`: reading it increments the
     * session usage index, and `AbstractSessionListener` turns that into
     * `Cache-Control: private, must-revalidate` — behind a lazy firewall, on every response,
     * including ones the application deliberately made public. Telemetry must not change what
     * the application sends, so the bundle asks for the untracked service instead.
     *
     * Asserted on the wiring rather than on a response, because the difference between the two
     * services *is* the wiring; reproducing it would mean booting a real lazy firewall.
     *
     * @throws \Throwable
     */
    #[Test]
    public function theUserAttributesReadTheUntrackedTokenStorage(): void
    {
        $container = $this->compile([
            'instrumentation' => ['http_server' => ['record_user_roles' => true]],
        ], configure: static function (ContainerBuilder $container): void {
            $container->register('security.untracked_token_storage', TokenStorage::class);
            $container->register('security.token_storage', TokenStorage::class);
        });

        // Named arguments are resolved to positions during the compile; the storage is first.
        self::assertSame(
            'security.untracked_token_storage',
            (string) $container->getDefinition(UserAttributes::class)->getArgument(0),
        );
    }

    /**
     * SecurityBundle registers both services together, so gating on the untracked one is not a
     * narrower condition than gating on the tracked one — but it has to be the one named,
     * or the subscriber would be registered with a reference nothing satisfies.
     *
     * @throws \Throwable
     */
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
