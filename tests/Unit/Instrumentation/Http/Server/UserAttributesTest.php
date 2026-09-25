<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\Instrumentation\Http\Server;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Identity on a span is the most consequential capture the bundle offers: a trace carrying
 * `user.id` is personal data, and the backend holding it is rarely governed as tightly as the
 * application's own database.
 */
#[CoversClass(UserAttributes::class)]
final class UserAttributesTest extends TestCase
{
    #[Test]
    public function nothingIsRecordedByDefault(): void
    {
        $users = new UserAttributes($this->storageFor('alice', ['ROLE_ADMIN']));

        self::assertFalse($users->wanted());
        self::assertSame([], $users->current());
    }

    #[Test]
    public function theIdentifierIsRecordedOnlyWhenAskedFor(): void
    {
        $storage = $this->storageFor('alice', ['ROLE_ADMIN']);

        self::assertSame(['user.id' => 'alice'], new UserAttributes($storage, recordUserId: true)->current());
    }

    #[Test]
    public function rolesAreRecordedWithoutTheIdentifier(): void
    {
        $storage = $this->storageFor('alice', ['ROLE_ADMIN', 'ROLE_USER']);

        self::assertSame(
            ['user.roles' => ['ROLE_ADMIN', 'ROLE_USER']],
            new UserAttributes($storage, recordUserRoles: true)->current(),
        );
    }

    #[Test]
    public function anUnauthenticatedRequestCarriesNothing(): void
    {
        $users = new UserAttributes(new TokenStorage(), recordUserId: true, recordUserRoles: true);

        self::assertTrue($users->wanted());
        self::assertSame([], $users->current());
    }

    #[Test]
    public function withoutSecurityInstalledThereIsNothingToRead(): void
    {
        $users = new UserAttributes(null, recordUserId: true, recordUserRoles: true);

        self::assertFalse($users->wanted());
        self::assertSame([], $users->current());
    }

    /** @param list<string> $roles */
    private function storageFor(string $identifier, array $roles): TokenStorage
    {
        $storage = new TokenStorage();
        $user = new InMemoryUser($identifier, null, $roles);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $roles));

        return $storage;
    }
}
