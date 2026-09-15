<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\DependencyInjection\CompilerPass;

use Nmspaced\TelemetryWeaver\DependencyInjection\DecoratedService;
use Nmspaced\TelemetryWeaver\DependencyInjection\DecorationChain;
use Nmspaced\TelemetryWeaver\DependencyInjection\InstrumentationGate;
use Nmspaced\TelemetryWeaver\Instrumentation\Serializer\SerializerTelemetry;
use Nmspaced\TelemetryWeaver\Instrumentation\Serializer\TraceableSerializer;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Serializer\Encoder\ContextAwareDecoderInterface;
use Symfony\Component\Serializer\Encoder\ContextAwareEncoderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class SerializerInstrumentationCompilerPass implements CompilerPassInterface
{
    private const string DEFAULT_SERIALIZER_ID = 'serializer';

    private const string NAMED_SERIALIZERS_PARAMETER = '.serializer.named_serializers';

    /**
     * Inside every other decorator on the serializer, and that is the point.
     *
     * Decoration priority ascends inward, so a higher number is applied earlier and ends
     * up closer to the decorated service. FrameworkBundle's own `debug.serializer` takes
     * the default 0, and at -32 this decorator sat outside it — which meant the object
     * handed to it in dev was `Debug\TraceableSerializer`, and that class implements five
     * of the interfaces below but not the two context-aware ones. The container then
     * fataled on the constructor's intersection type, in the error controller, where a
     * fatal takes the error page with it.
     *
     * Being innermost is also the better measurement: the span covers the serializer
     * doing the work, not the profiler's wrapper around it.
     */
    private const int DECORATION_PRIORITY = 32;

    /**
     * Every interface the decorator has to forward; a serializer missing one
     * of them cannot be wrapped without narrowing what its callers can do.
     * Symfony's own Serializer implements all five.
     *
     * @var list<class-string>
     */
    private const array REQUIRED_INTERFACES = [
        SerializerInterface::class,
        NormalizerInterface::class,
        DenormalizerInterface::class,
        ContextAwareEncoderInterface::class,
        ContextAwareDecoderInterface::class,
    ];

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $gate = InstrumentationGate::bundle($container)
            ->requires('symfony/serializer', SerializerInterface::class)
            ->instruments('serializer');

        if ($gate->isClosed()) {
            return;
        }

        foreach ($this->serializerIds($container) as $id => $name) {
            $this->decorate($container, $id, $name);
        }
    }

    /**
     * @return iterable<string, non-empty-string> service id => serializer name
     */
    private function serializerIds(ContainerBuilder $container): iterable
    {
        yield self::DEFAULT_SERIALIZER_ID => 'default';

        foreach ($this->namedSerializers($container) as $name) {
            yield \sprintf('%s.%s', self::DEFAULT_SERIALIZER_ID, $name) => $name;
        }
    }

    /**
     * @return list<non-empty-string>
     */
    private function namedSerializers(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::NAMED_SERIALIZERS_PARAMETER)) {
            return [];
        }

        $named = $container->getParameter(self::NAMED_SERIALIZERS_PARAMETER);

        if (!\is_array($named)) {
            return [];
        }

        /** @var list<non-empty-string> */
        return \array_values(\array_filter(
            \array_keys($named),
            static fn(string|int $name): bool => \is_string($name) && $name !== '',
        ));
    }

    /**
     * @param non-empty-string $name
     */
    private function decorate(ContainerBuilder $container, string $id, string $name): void
    {
        if (!$container->hasDefinition($id) || $container->getDefinition($id)->isAbstract()) {
            return;
        }

        // The class this decorator will actually be handed, which is not necessarily the
        // decorated service's: anything decorating it at a higher priority lands between
        // the two. Checking the wrong one is what produced a constructor TypeError at
        // runtime instead of a skipped decoration at compile time.
        $class = $this->resolveClass($container, DecorationChain::innerId($container, $id, self::DECORATION_PRIORITY));

        if ($class === null || !$this->isFullSerializer($class)) {
            return;
        }

        $innerId = DecoratedService::innerId($id);

        $container
            ->register(DecoratedService::id($id), TraceableSerializer::class)
            ->setDecoratedService($id, $innerId, self::DECORATION_PRIORITY)
            ->setArgument('$delegate', new Reference($innerId))
            ->setArgument('$serializerTelemetry', new Reference(SerializerTelemetry::class))
            ->setArgument('$serializerName', $name);
    }

    /**
     * @param class-string $class
     */
    private function isFullSerializer(string $class): bool
    {
        $implemented = \class_implements($class);

        return $implemented !== false && \array_diff(self::REQUIRED_INTERFACES, $implemented) === [];
    }

    /**
     * A named serializer is registered as a child of "serializer" and inherits
     * its class, so the parents have to be walked before the class is known.
     *
     * @return class-string|null
     */
    private function resolveClass(ContainerBuilder $container, string $id): ?string
    {
        $definition = $container->findDefinition($id);

        while ($definition->getClass() === null && $definition instanceof ChildDefinition) {
            $definition = $container->findDefinition($definition->getParent());
        }

        /** @var mixed $class */
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        return \is_string($class) && \class_exists($class) ? $class : null;
    }
}
