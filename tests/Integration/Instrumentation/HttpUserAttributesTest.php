<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Instrumentation;

use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributes;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributesSubscriber;
use Nmspaced\TelemetryWeaver\Tests\Fake\SpyTokenStorage;
use Nmspaced\TelemetryWeaver\Tests\Support\HttpTelemetryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/** User attributes set during `kernel.request` still land on the server span. */
#[CoversClass(UserAttributesSubscriber::class)]
#[CoversClass(UserAttributes::class)]
final class HttpUserAttributesTest extends HttpTelemetryTestCase
{
    private TokenStorage $tokens;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new TokenStorage();
    }

    /** @throws \Throwable */
    #[Test]
    public function theAuthenticatedUserLandsOnTheServerSpan(): void
    {
        $this->authenticate('alice', ['ROLE_ADMIN']);
        $this->listen(recordUserId: true, recordUserRoles: true);

        $this->handle($this->request(static fn(): Response => new Response()));

        $attributes = $this->exportedSpan()->getAttributes();
        self::assertSame('alice', $attributes->get('user.id'));
        self::assertSame(['ROLE_ADMIN'], $attributes->get('user.roles'));
        $this->assertNoReports();
    }

    /** @throws \Throwable */
    #[Test]
    public function nothingIsWrittenForAnUnauthenticatedRequest(): void
    {
        $this->listen(recordUserId: true, recordUserRoles: true);

        $this->handle($this->request(static fn(): Response => new Response()));

        $attributes = $this->exportedSpan()->getAttributes();
        self::assertNull($attributes->get('user.id'));
        self::assertNull($attributes->get('user.roles'));
    }

    /** @throws \Throwable */
    #[Test]
    public function onlyTheServerSpanCarriesTheIdentity(): void
    {
        $this->authenticate('alice', ['ROLE_ADMIN']);
        $this->listen(recordUserId: true);

        $this->handle($this->request(
            /** @throws \Throwable */
            function (): Response {
                $this->authenticate('mallory', ['ROLE_USER']);
                $this->subRequest(static fn(): Response => new Response('fragment'));

                return new Response();
            },
        ));

        self::assertCount(2, $this->exported());
        self::assertNull($this->exportedSpan(0)->getAttributes()->get('user.id'), 'the sub-request span carries none');
        self::assertSame('alice', $this->exportedSpan(1)->getAttributes()->get('user.id'));
    }

    /** @throws \Throwable */
    #[Test]
    public function anUntracedRequestReadsNoToken(): void
    {
        $this->boot(excludedPaths: ['/orders']);
        $spy = new SpyTokenStorage();
        $this->dispatcher->addSubscriber(
            new UserAttributesSubscriber(
                $this->scopes,
                new UserAttributes($spy, recordUserId: true, recordUserRoles: true),
                $this->reporter,
            ),
        );

        $this->handle($this->request(static fn(): Response => new Response(), '/orders/7'));

        self::assertSame([], $this->exported(), 'the path is excluded, so there is no span');
        self::assertSame(0, $spy->reads, 'and therefore nothing to read the token for');
    }

    /** @param list<string> $roles */
    private function authenticate(string $identifier, array $roles): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(new InMemoryUser($identifier, null, $roles), 'main', $roles));
    }

    private function listen(bool $recordUserId = false, bool $recordUserRoles = false): void
    {
        $this->dispatcher->addSubscriber(
            new UserAttributesSubscriber(
                $this->scopes,
                new UserAttributes($this->tokens, $recordUserId, $recordUserRoles),
                $this->reporter,
            ),
        );
    }
}
