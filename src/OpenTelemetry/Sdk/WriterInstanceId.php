<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SemConv\Incubating\Attributes\ContainerIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HostIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ProcessIncubatingAttributes;

/**
 * @internal A stable `service.instance.id` for one FPM child.
 *
 * With request metrics every FPM child writes its own delta stream. `process.pid` and the host
 * tell children apart, but the Prometheus mapping ignores them: it keys series by `job` and
 * `instance`, taken from `service.name` and `service.instance.id`. Without an instance id the
 * cumulative series a collector rebuilds per child collapse into one and overwrite each other.
 *
 * The SDK's random id does not help: its static dies with the request, so every request would be
 * a new instance. A name-based UUID v5, as the semantic conventions recommend, is the same on
 * every request of a child and differs between children.
 *
 * Every identifying attribute present goes into the name, because containers often share
 * `host.id`. Without a pid, or without a host or container, nothing is derived.
 */
final readonly class WriterInstanceId
{
    /** Namespace of the derived ids; changing it changes every id. */
    private const string NAMESPACE = 'bc0e94faf86848568f961b72d9555dd9';

    /** Where the process runs; at least one is required. */
    private const array PLACE = [
        ContainerIncubatingAttributes::CONTAINER_ID,
        HostIncubatingAttributes::HOST_ID,
        HostIncubatingAttributes::HOST_NAME,
    ];

    /** @param array<array-key, mixed> $attributes a resource's attributes */
    public static function derive(array $attributes): ?string
    {
        /** @var mixed $pid */
        $pid = $attributes[ProcessIncubatingAttributes::PROCESS_PID] ?? null;
        if (!\is_int($pid)) {
            return null;
        }

        $name = [];
        foreach (self::PLACE as $key) {
            /** @var mixed $value */
            $value = $attributes[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                $name[] = \sprintf('%s=%s', $key, $value);
            }
        }

        if ($name === []) {
            return null;
        }

        $name[] = \sprintf('%s=%d', ProcessIncubatingAttributes::PROCESS_PID, $pid);

        return self::uuid5(\implode("\n", $name));
    }

    /** RFC 4122 name-based UUID: SHA-1 of namespace and name, with version and variant bits set. */
    private static function uuid5(string $name): string
    {
        $hash = \sha1(\sprintf('%s%s', (string) \hex2bin(self::NAMESPACE), $name));

        return \sprintf(
            '%s-%s-5%s-%x%s-%s',
            \substr($hash, 0, 8),
            \substr($hash, 8, 4),
            \substr($hash, 13, 3),
            ((int) \hexdec($hash[16]) & 0x3) | 0x8,
            \substr($hash, 17, 3),
            \substr($hash, 20, 12),
        );
    }
}
