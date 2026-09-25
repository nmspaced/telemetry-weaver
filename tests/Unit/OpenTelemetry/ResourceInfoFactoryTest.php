<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\Sdk\ResourceInfoFactory;
use OpenTelemetry\SemConv\Incubating\Attributes\ServiceIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceInfoFactory::class)]
final class ResourceInfoFactoryTest extends TestCase
{
    #[Test]
    public function aWorkerResourceCarriesAServiceInstanceIdThatIsStableWithinTheProcess(): void
    {
        $first = new ResourceInfoFactory(self::worker())
            ->create()
            ->getAttributes()
            ->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID);
        $second = new ResourceInfoFactory(self::worker())
            ->create()
            ->getAttributes()
            ->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID);

        self::assertIsString($first);
        self::assertNotSame('', $first);
        self::assertSame($first, $second);
    }

    #[Test]
    public function aRequestResourceDoesNotInventAServiceInstanceId(): void
    {
        $resource = new ResourceInfoFactory(SymfonyRuntimeProfile::fromKernel(0, true))->create();

        self::assertFalse($resource->getAttributes()->has(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID));
        self::assertSame(Version::VERSION_1_44_0->url(), $resource->getSchemaUrl());
    }

    #[Test]
    public function requestMetricsGiveAnFpmChildAnIdStableAcrossItsRequests(): void
    {
        $fpm = SymfonyRuntimeProfile::fromKernel(0, true);

        $first = new ResourceInfoFactory($fpm, requestMetrics: 'delta')->create()->getAttributes();
        $second = new ResourceInfoFactory($fpm, requestMetrics: 'delta')->create()->getAttributes();

        self::assertIsInt($first->get('process.pid'), 'the SDK process detector identifies the child');
        self::assertIsString($first->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID));
        self::assertSame(
            $first->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID),
            $second->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID),
        );
    }

    #[Test]
    public function aConfiguredIdWinsOverTheDerivedOne(): void
    {
        $resource = new ResourceInfoFactory(
            SymfonyRuntimeProfile::fromKernel(0, true),
            [ServiceIncubatingAttributes::SERVICE_INSTANCE_ID => 'pool-a'],
            requestMetrics: 'delta',
        )->create();

        self::assertSame('pool-a', $resource->getAttributes()->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID));
    }

    #[Test]
    public function aResetKernelWorkerKeepsItsDetectedIdWithRequestMetrics(): void
    {
        $detected = new ResourceInfoFactory(SymfonyRuntimeProfile::fromKernel(2, true))
            ->create()
            ->getAttributes()
            ->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID);
        $withMetrics = new ResourceInfoFactory(SymfonyRuntimeProfile::fromKernel(2, true), requestMetrics: 'delta')
            ->create()
            ->getAttributes()
            ->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID);

        self::assertIsString($detected);
        self::assertSame($detected, $withMetrics);
    }

    private static function worker(): SymfonyRuntimeProfile
    {
        return SymfonyRuntimeProfile::fromKernel(1, true);
    }

    #[Test]
    public function theResourceIsPublishedUnderTheSemanticConventionsBaseline(): void
    {
        $resource = new ResourceInfoFactory(self::worker(), [
            ServiceIncubatingAttributes::SERVICE_INSTANCE_ID => 'worker-7',
        ])->create();

        self::assertSame(Version::VERSION_1_44_0->url(), $resource->getSchemaUrl());
    }

    /**
     * Stamping the detectors' attributes with a newer schema is a claim that they are still what
     * that release calls them.
     */
    /** @throws \ReflectionException */
    #[Test]
    public function everyDetectedAttributeIsCurrentInTheBaseline(): void
    {
        $registry = self::semanticConventionsRegistry();
        $keys = \array_keys(new ResourceInfoFactory(self::worker())->create()->getAttributes()->toArray());

        self::assertNotSame([], $keys);

        foreach ($keys as $key) {
            self::assertArrayHasKey(
                $key,
                $registry,
                \sprintf('"%s" is not in the semantic conventions registry', $key),
            );
            self::assertFalse(
                $registry[$key] ?? Assert::fail('missing registry entry: ' . $key),
                \sprintf('"%s" is deprecated in the semantic conventions baseline', $key),
            );
        }
    }

    /**
     * Every attribute the installed conventions package defines, and whether it is deprecated.
     *
     * @return array<string, bool>
     *
     * @throws \ReflectionException
     */
    private static function semanticConventionsRegistry(): array
    {
        $root = \dirname((string) new \ReflectionClass(Version::class)->getFileName());
        $registry = [];

        foreach (['Attributes', 'Incubating/Attributes'] as $directory) {
            $namespace = 'OpenTelemetry\\SemConv\\' . \str_replace('/', '\\', $directory) . '\\';

            $files = \glob($root . '/' . $directory . '/*.php');
            $files = $files === false ? [] : $files;

            foreach ($files as $file) {
                /** @var class-string $fqcn */
                $fqcn = $namespace . \basename($file, '.php');
                $class = new \ReflectionClass($fqcn);

                foreach ($class->getReflectionConstants() as $constant) {
                    $value = $constant->getValue();

                    if (!\is_string($value)) {
                        continue;
                    }

                    $deprecated = \str_contains((string) $constant->getDocComment(), '@deprecated');
                    $registry[$value] = ($registry[$value] ?? true) && $deprecated;
                }
            }
        }

        return $registry;
    }

    #[Test]
    public function aConfiguredServiceInstanceIdWinsOverTheGeneratedOne(): void
    {
        $resource = new ResourceInfoFactory(self::worker(), [
            ServiceIncubatingAttributes::SERVICE_INSTANCE_ID => 'worker-7',
        ])->create();

        self::assertSame('worker-7', $resource->getAttributes()->get(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID));
    }
}
