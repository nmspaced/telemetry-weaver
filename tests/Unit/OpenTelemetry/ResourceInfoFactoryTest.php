<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Unit\OpenTelemetry;

use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use Nmspaced\TelemetryWeaver\OpenTelemetry\ResourceInfoFactory;
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
        // A kernel reboot builds a new resource; the worker it describes has not changed.
        self::assertSame($first, $second);
    }

    /**
     * Under FPM the detector's static does not outlive the request, so the id would be a
     * new instance per request. The SDK default resource is left as it is.
     */
    #[Test]
    public function aRequestResourceDoesNotInventAServiceInstanceId(): void
    {
        $resource = new ResourceInfoFactory(SymfonyRuntimeProfile::fromKernel(0, true))->create();

        self::assertFalse($resource->getAttributes()->has(ServiceIncubatingAttributes::SERVICE_INSTANCE_ID));
        self::assertSame(Version::VERSION_1_44_0->url(), $resource->getSchemaUrl());
    }

    private static function worker(): SymfonyRuntimeProfile
    {
        return SymfonyRuntimeProfile::fromKernel(1, true);
    }

    /**
     * The detectors declare an older schema, and `ResourceInfo::merge()` drops the URL to
     * null when two sides disagree — so the baseline has to survive merging with them,
     * configured attributes included.
     */
    #[Test]
    public function theResourceIsPublishedUnderTheSemanticConventionsBaseline(): void
    {
        $resource = new ResourceInfoFactory(self::worker(), [
            ServiceIncubatingAttributes::SERVICE_INSTANCE_ID => 'worker-7',
        ])->create();

        self::assertSame(Version::VERSION_1_44_0->url(), $resource->getSchemaUrl());
    }

    /**
     * Stamping the detectors' attributes with a newer schema is a claim that they are
     * still what that release calls them. Every key must exist in the release's registry
     * and must not be deprecated there; an SDK upgrade that adds an attribute the
     * baseline renamed, or a baseline bump that deprecates one, fails here instead of
     * silently publishing a wrong schema.
     */
    /**
     * @throws \ReflectionException
     */
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
     * Every attribute the installed conventions package defines, and whether it is
     * deprecated.
     *
     * Only the `Attributes` and `Incubating\Attributes` namespaces: the package generates
     * them without attributes that were removed or renamed (`http.method`, `db.system`
     * survive only in the legacy `TraceAttributes` / `ResourceAttributes` classes), so
     * presence here is the main check. The few that are kept but deprecated carry an
     * `@deprecated` tag; a key counts as deprecated only if every declaration says so.
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
