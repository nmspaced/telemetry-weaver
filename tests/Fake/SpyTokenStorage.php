<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Counts reads, because reading is the part with consequences.
 *
 * Symfony's tracking token storage turns a single `getToken()` behind a lazy firewall into
 * `Cache-Control: private` on the response. The bundle reads the untracked one instead, but
 * "how often" still matters: a request the bundle is not tracing must not read at all.
 */
final class SpyTokenStorage implements TokenStorageInterface
{
    public int $reads = 0;

    private ?TokenInterface $token = null;

    #[\Override]
    public function getToken(): ?TokenInterface
    {
        ++$this->reads;

        return $this->token;
    }

    #[\Override]
    public function setToken(#[\SensitiveParameter] ?TokenInterface $token): void
    {
        $this->token = $token;
    }
}
