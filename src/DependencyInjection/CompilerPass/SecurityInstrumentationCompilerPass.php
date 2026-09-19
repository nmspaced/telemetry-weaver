<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributes;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Security\UserAttributesSubscriber;
use Nmspaced\TelemetryWeaver\Instrumentation\Http\Server\Tracing\RequestTraceRegistry;
use Nmspaced\TelemetryWeaver\Internal\Diagnostics\InstrumentationFailureReporter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The authenticated user, as attributes on the server span.
 *
 * Part of `http_server` rather than a component of its own: it opens no span, starts no
 * operation and records no metric — it adds two keys to a span another subscriber already
 * owns, which is exactly what `record_client_ip` does. A `security` component would have
 * implied a signal switch, a meter and a telemetry facade that nothing would use.
 *
 * Nothing is registered unless a key asks for it. Both are off by default, so the common
 * case costs the container two definitions it never creates and a request zero work.
 *
 * ## Why `security.untracked_token_storage`
 *
 * `security.token_storage` is a `UsageTrackingTokenStorage`, and reading it does more than
 * answer the question: it increments the session's usage index, and
 * `AbstractSessionListener` turns that into `Cache-Control: private, must-revalidate` with
 * `max-age=0` on the response. Behind a lazy firewall that is *every* response, including the
 * public ones an application deliberately made cacheable.
 *
 * Observing who is logged in must not change what the application sends. The untracked
 * storage is the service Symfony provides for exactly this — its own profiler data collector
 * uses it — and it answers the same question without the side effect.
 */
final readonly class SecurityInstrumentationCompilerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/security-core', TokenStorageInterface::class)
            ->needs('security.untracked_token_storage')
            ->instruments('http_server');

        if ($gate->isClosed() || !$container->hasDefinition(RequestTraceRegistry::class)) {
            return;
        }

        $recordUserId = $container->getParameter('open_telemetry.instrumentation.http_server.record_user_id') === true;
        $recordRoles =
            $container->getParameter('open_telemetry.instrumentation.http_server.record_user_roles') === true;

        if (!$recordUserId && !$recordRoles) {
            return;
        }

        $container
            ->register(UserAttributes::class, UserAttributes::class)
            ->setArgument('$tokenStorage', new Reference('security.untracked_token_storage'))
            ->setArgument('$recordUserId', $recordUserId)
            ->setArgument('$recordUserRoles', $recordRoles);

        $container
            ->register(UserAttributesSubscriber::class, UserAttributesSubscriber::class)
            ->setArgument('$requestTraces', new Reference(RequestTraceRegistry::class))
            ->setArgument('$users', new Reference(UserAttributes::class))
            ->setArgument('$reporter', new Reference(InstrumentationFailureReporter::class))
            ->addTag('kernel.event_subscriber');
    }
}
