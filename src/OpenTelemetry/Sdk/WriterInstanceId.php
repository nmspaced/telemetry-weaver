<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use OpenTelemetry\SemConv\Incubating\Attributes\ContainerIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HostIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ProcessIncubatingAttributes;

/**
 * @internal
 *
 * A stable `service.instance.id` for one FPM child: a UUID v5 of its host and pid, so each
 * child's delta metrics stay a separate series. Null when those attributes are missing.
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
