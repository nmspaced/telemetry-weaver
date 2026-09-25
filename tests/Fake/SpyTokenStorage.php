<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Fake;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/** A token storage that counts reads. */
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
