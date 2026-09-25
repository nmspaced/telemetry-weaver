<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\Configuration;

use Nmspaced\TelemetryWeaver\Instrumentation\Doctrine\QueryText;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Configuration of components on the request path.
 *
 * @internal
 */
final class RequestComponents
{
    /**
     * @var list<string>
     */
    private const array DEFAULT_EXCLUDED_HTTP_PATHS = [
        '/_profiler',
        '/_wdt',
        '/health',
    ];

    /**
     * Worker commands that are never traced: a process-long span is never exported and would
     * swallow every message trace. Messenger already traces their work per message.
     *
     * @var list<string>
     */
    private const array LONG_RUNNING_CONSOLE_COMMANDS = [
        'messenger:consume',
        'messenger:consume-messages',
    ];

    /**
     * Default exclusions, replaced when the key is set.
     *
     * @var list<string>
     */
    private const array DEFAULT_EXCLUDED_CONSOLE_COMMANDS = [
        'cache:clear',
        'cache:warmup',
        'assets:install',
        'lint:container',
        'lint:yaml',
    ];

    /** @throws \RuntimeException */
    public function httpServer(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component(
            'http_server',
            'Incoming HTTP requests.',
            overridableOption: 'excluded_paths',
        );

        $node
            ->children()
            ->arrayNode('excluded_paths')
            ->performNoDeepMerging()
            ->info('Request path prefixes to skip. A signal can narrow or widen this with its own list.')
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue(self::DEFAULT_EXCLUDED_HTTP_PATHS)
            ->end()
            ->booleanNode('record_client_ip')
            ->info('Record client.address. Off by default: an IP address is personal data in most jurisdictions.')
            ->defaultFalse()
            ->end()
            ->booleanNode('record_user_id')
            ->info(
                'Record user.id from the authenticated token. Off by default: it is personal data. Needs symfony/security-core.',
            )
            ->defaultFalse()
            ->end()
            ->booleanNode('record_user_roles')
            ->info('Record user.roles from the authenticated token. Off by default. Needs symfony/security-core.')
            ->defaultFalse()
            ->end()
            ->integerNode('record_exception_min_status')
            ->min(400)
            ->max(599)
            ->info(
                'Lowest response status at which the exception is recorded as a span event. Whether the span is errored is not configurable: the conventions say >= 500.',
            )
            ->defaultValue(500)
            ->end()
            ->end();

        return $node;
    }

    /** @throws \RuntimeException */
    public function httpClient(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component(
            'http_client',
            'Outgoing HTTP requests.',
            overridableOption: 'excluded_hosts',
        );

        $node
            ->children()
            ->arrayNode('excluded_hosts')
            ->performNoDeepMerging()
            ->info('Remote hosts to skip — typically the collector itself, to keep telemetry from tracing telemetry.')
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue([])
            ->end()
            ->end();

        return $node;
    }

    /** @throws \RuntimeException */
    public function console(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component('console', 'Console commands.');

        $node
            ->children()
            ->arrayNode('excluded_commands')
            ->performNoDeepMerging()
            ->info(
                'Command names to skip. Replaces the defaults; Messenger workers are always skipped, since their work is traced per message.',
            )
            ->stringPrototype()
            ->cannotBeEmpty()
            ->end()
            ->defaultValue([...self::LONG_RUNNING_CONSOLE_COMMANDS, ...self::DEFAULT_EXCLUDED_CONSOLE_COMMANDS])
            ->validate()
            ->always(self::withLongRunningCommands(...))
            ->end()
            ->end()
            ->end();

        return $node;
    }

    /**
     * @param list<string> $commands
     *
     * @return list<string>
     */
    private static function withLongRunningCommands(array $commands): array
    {
        return \array_values(\array_unique([
            ...self::LONG_RUNNING_CONSOLE_COMMANDS,
            ...$commands,
        ]));
    }

    /** @throws \RuntimeException */
    public function doctrine(): ArrayNodeDefinition
    {
        $node = ComponentSignal::component('doctrine', 'Doctrine DBAL queries.');

        $node
            ->children()
            ->enumNode('query_text')
            ->info(
                'What db.query.text carries: "sanitized" (literals replaced by ?), "raw" (as sent) or "off". true and false mean "raw" and "off".',
            )
            ->beforeNormalization()
            ->ifTrue(static fn(mixed $value): bool => \is_bool($value))
            ->then(static fn(bool $value): string => $value ? QueryText::Raw->value : QueryText::Off->value)
            ->end()
            ->values(\array_map(static fn(QueryText $mode): string => $mode->value, QueryText::cases()))
            ->defaultValue(QueryText::Sanitized->value)
            ->end()
            ->booleanNode('only_with_parent')
            ->info(
                'Create query spans only inside an existing trace. Keeps orphan spans of background polling out; metrics are recorded either way.',
            )
            ->defaultTrue()
            ->end()
            ->booleanNode('transactions')
            ->info(
                'Record BEGIN, COMMIT and ROLLBACK as operations of their own. Nested levels are traced as SAVEPOINT statements.',
            )
            ->defaultTrue()
            ->end()
            ->end();

        return $node;
    }
}
