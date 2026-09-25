<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk;

use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\Detectors\ServiceInstance;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory as ResourceInfoFactorySDK;
use OpenTelemetry\SemConv\Incubating\Attributes\ServiceIncubatingAttributes;
use OpenTelemetry\SemConv\Version;

/**
 * Builds the resource all signals are exported under.
 *
 * Adds `service.instance.id` where the PHP execution outlives a request (workers, CLI), so
 * workers do not overwrite each other's series; under FPM it is added only for delta request
 * metrics (`WriterInstanceId`). Configured attributes win, and the result is stamped with the
 * bundle's semantic conventions schema.
 */
final readonly class ResourceInfoFactory
{
    /**
     * @param iterable<string, mixed> $attributes
     * @param 'disabled'|'delta' $requestMetrics `runtime.request_metrics.mode`
     */
    public function __construct(
        private SymfonyRuntimeProfile $runtime,
        private iterable $attributes = [],
        private Version $version = Version::VERSION_1_44_0,
        private string $requestMetrics = 'disabled',
    ) {}

    public function create(): ResourceInfo
    {
        $detected = ResourceInfoFactorySDK::defaultResource();

        $identity = match (true) {
            $this->runtime->hasWorkerIdentity() => new ServiceInstance()->getResource(),
            $this->requestMetrics === 'delta' => self::derivedInstance($detected),
            default => ResourceInfo::emptyResource(),
        };

        $merged = $identity
            ->merge($detected)
            ->merge(ResourceInfo::create(Attributes::create($this->attributes)));

        return ResourceInfo::create($merged->getAttributes(), $this->version->url());
    }

    /**
     * The FPM child's id from its host and pid; empty when they are not detected.
     */
    private static function derivedInstance(ResourceInfo $detected): ResourceInfo
    {
        $id = WriterInstanceId::derive($detected->getAttributes()->toArray());

        return ResourceInfo::create(Attributes::create(\array_filter([
            ServiceIncubatingAttributes::SERVICE_INSTANCE_ID => $id,
        ])));
    }
}
