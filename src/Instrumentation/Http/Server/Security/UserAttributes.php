<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security;

use OpenTelemetry\SemConv\Incubating\Attributes\UserIncubatingAttributes;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Who the firewall says is making this request, as span attributes.
 *
 * Both keys are off by default and separately switchable, because they are different
 * decisions. `user.id` names a person: in most jurisdictions putting it in a trace makes the
 * trace personal data, with everything that follows — retention, access, erasure requests —
 * and a backend's traces are rarely governed as tightly as an application's database.
 * `user.roles` names a group, so it is the one that is usually safe, and it is also the one
 * that answers the question people actually ask of a trace: was this slow for admins, or for
 * everyone.
 *
 * The identifier comes from `UserInterface::getUserIdentifier()` — the value the application
 * already chose to log in with. It is not hashed here: a hash whose salt this bundle picked
 * would be neither reversible for support nor stable across deployments, and `user.hash`
 * exists for applications that want to compute a real one themselves.
 *
 * Nothing is read outside a firewall: with no token, or with an unauthenticated one, this
 * returns nothing rather than a placeholder — "anonymous" as an attribute value is a series
 * and a lie at the same time.
 *
 * The storage handed in is `security.untracked_token_storage`, not `security.token_storage`.
 * The latter tracks reads, and behind a lazy firewall a single read turns every response
 * into `Cache-Control: private, must-revalidate` — telemetry changing what the application
 * sends, which is the one thing instrumentation must never do. See
 * {@see \Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass\SecurityInstrumentationCompilerPass}.
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
