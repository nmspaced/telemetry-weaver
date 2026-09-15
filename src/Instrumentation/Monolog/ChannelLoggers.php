<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Monolog;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;

/**
 * One OpenTelemetry logger per Monolog channel, kept for the life of the process — up to
 * a ceiling.
 *
 * Process-scoped on purpose, and capped rather than trusted to be bounded. Channels
 * declared in configuration are a fixed set, but `Logger::withName()` turns any string
 * into one, and the SDK does not cache loggers itself — it hands out a new one per call
 * and holds them only weakly — so this map would be the one thing keeping every name a
 * worker ever saw alive. Past the cap a logger is created for the record and dropped with
 * it: slower for a pathological application, never larger.
 *
 * Not readonly: the map is the state this class exists to hold.
 */
final class ChannelLoggers
{
    /**
     * Far above what a configured application declares, and a hard ceiling for one that
     * makes channels out of data.
     */
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
