<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;

/**
 * One OpenTelemetry logger per Monolog channel, cached for the process up to {@see self::MAX}.
 * Channel names can come from data (`Logger::withName()`), so past the cap loggers are not cached.
 */
final class ChannelLoggers
{
    /** Upper bound on cached channel loggers. */
    public const int MAX = 128;

    /** @var array<string, LoggerInterface> */
    private array $loggers = [];

    public function __construct(
        private readonly LoggerProviderInterface $provider,
    ) {}

    public function of(string $channel): LoggerInterface
    {
        $cached = $this->loggers[$channel] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        $logger = $this->provider->getLogger($channel);

        if (\count($this->loggers) < self::MAX) {
            $this->loggers[$channel] = $logger;
        }

        return $logger;
    }
}
