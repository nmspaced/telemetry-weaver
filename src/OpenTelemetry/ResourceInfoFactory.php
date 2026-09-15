<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\Detectors\ServiceInstance;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory as ResourceInfoFactorySDK;
use OpenTelemetry\SemConv\Version;

/**
 * The resource every signal is exported under — with a `service.instance.id` wherever the
 * PHP execution outlives a request, and without one where it does not.
 *
 * The SDK leaves that attribute out by default — its `all` detector set omits the
 * `ServiceInstance` detector, on the grounds that a random id is useless in
 * shared-nothing FPM. In worker mode it is the opposite of useless. Several workers of
 * one service export the same instruments under the same attributes, and without an
 * instance id they are one series to the backend: cumulative sums from different
 * workers overwrite each other and read as a stream of counter resets, and a memory
 * curve assembled from whichever worker exported last cannot show a leak. The semantic
 * conventions require the id to be unique per instance for exactly this reason.
 *
 * Under FPM the SDK is right, and measurably so: the detector's function-static does not
 * survive the request, so two requests served by the same child with the same PID get two
 * different ids — a new instance per request, which is cardinality and not identity. There
 * the SDK default resource stands as it is (`process.pid` and host attributes identify the
 * writer; see `RequestMetricPolicy`). An id the user asked for is still kept: naming
 * `service_instance` in `OTEL_PHP_DETECTORS` or setting the attribute explicitly is not
 * silently undone.
 *
 * Which of the two applies is decided by `SymfonyRuntimeProfile::hasWorkerIdentity()`: any
 * worker mode, including the one that clones its kernel after each request — the container
 * is replaced but the PHP execution, and with it the detector's static, lives on — and the
 * CLI, where a Messenger consumer is exactly the long-lived writer the id exists for.
 *
 * The id comes from the SDK detector rather than from here, and that choice carries two
 * properties. It is held in a function-static, so it is one value per PHP execution
 * context: per process under RoadRunner, per worker thread under FrankenPHP's ZTS
 * build, and stable across kernel reboots within either. And it is the same value the
 * detector yields when `OTEL_PHP_DETECTORS` already names it, so enabling it both ways
 * cannot produce two ids.
 *
 * It is merged first, so it is only a default: an id set through
 * `OTEL_RESOURCE_ATTRIBUTES` or the bundle's `resource_attributes` wins. The price is a
 * series per worker for every metric, and a new set of series each time a worker is
 * recycled — bounded by the backend's staleness handling, and the only honest shape for
 * data several writers produce.
 *
 * The resource is published under the same semantic conventions baseline as the scope.
 * That cannot be done by giving the configured part a newer schema and merging it:
 * `ResourceInfo::merge()` resolves two different schema URLs to *none*, with a warning,
 * and the SDK detectors all declare an older one. So the detected and configured
 * attributes are merged first — the configured part carrying no schema, which merge()
 * treats as agreement — and the result is stamped once. That stamp is only honest while
 * every detected key is a current, non-deprecated attribute of the baseline;
 * `ResourceInfoFactoryTest` checks exactly that, and fails when an SDK upgrade or a
 * baseline bump breaks it.
 */
final readonly class ResourceInfoFactory
{
    /**
     * @param iterable<string, mixed> $attributes
     */
    public function __construct(
        private SymfonyRuntimeProfile $runtime,
        private iterable $attributes = [],
        private Version $version = Version::VERSION_1_44_0,
    ) {}

    public function create(): ResourceInfo
    {
        $resource = ResourceInfoFactorySDK::defaultResource();
        if ($this->runtime->hasWorkerIdentity()) {
            $resource = new ServiceInstance()
                ->getResource()
                ->merge($resource);
        }

        $merged = $resource->merge(ResourceInfo::create(Attributes::create($this->attributes)));

        return ResourceInfo::create($merged->getAttributes(), $this->version->url());
    }
}
