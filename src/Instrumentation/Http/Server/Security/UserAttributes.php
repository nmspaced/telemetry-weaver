<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security;

use OpenTelemetry\SemConv\Incubating\Attributes\UserIncubatingAttributes;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * `user.id` and `user.roles` for the authenticated user, each off by default because `user.id`
 * makes a trace personal data. Nothing is recorded without an authenticated token.
 *
 * Reads `security.untracked_token_storage`: the tracked storage would make responses private.
 *
 * @internal
 */
final readonly class UserAttributes
{
    public function __construct(
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $recordUserId = false,
        private bool $recordUserRoles = false,
    ) {}

    public function wanted(): bool
    {
        return $this->tokenStorage !== null && ($this->recordUserId || $this->recordUserRoles);
    }

    /**
     * @return array<non-empty-string, string|list<string>>
     */
    public function current(): array
    {
        if (!$this->wanted()) {
            return [];
        }

        $token = $this->tokenStorage?->getToken();

        if ($token === null) {
            return [];
        }

        $user = $token->getUser();

        if ($user === null) {
            return [];
        }

        $attributes = [];

        if ($this->recordUserId) {
            $attributes[UserIncubatingAttributes::USER_ID] = $user->getUserIdentifier();
        }

        if ($this->recordUserRoles) {
            $roles = \array_values(\array_unique($token->getRoleNames()));
            if ($roles !== []) {
                $attributes[UserIncubatingAttributes::USER_ROLES] = $roles;
            }
        }

        return $attributes;
    }
}
