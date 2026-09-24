<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Integration\Bundle;

use Monolog\Handler\TestHandler;
use Nmspaced\TelemetryWeaver\Api\ActiveTrace;
use Nmspaced\TelemetryWeaver\Api\Telemetry;
use Nmspaced\TelemetryWeaver\Api\TraceContext;
use Nmspaced\TelemetryWeaver\Tests\Support\ContainerTestCase;
use OpenTelemetry\Context\ContextStorageInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ActiveTraceLogIntegrationTest extends ContainerTestCase
{
    /** @throws \Throwable */
    #[Test]
    public function activeTraceAndRealMonologResolveAndCorrelate(): void
    {
        $container = $this->compileWithMonolog();
        $activeTrace = $container->get(ActiveTrace::class);
        $logger = $container->get(LoggerInterface::class);
        $handler = $container->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);

        $current = $container->get(Telemetry::class)->trace('logged', static function () use (
            $logger,
            $activeTrace,
        ): ?TraceContext {
            $logger->info('inside operation');

            return $activeTrace->current();
        });

        self::assertNotNull($current);
        self::assertSame($current->traceId, $handler->getRecords()[0]->extra['trace_id'] ?? null);
        self::assertSame($current->spanId, $handler->getRecords()[0]->extra['span_id'] ?? null);
    }

    /** @throws \Throwable */
    #[Test]
    public function aContextReadFailureDoesNotReenterLogProcessing(): void
    {
        $storage = $this->createMock(ContextStorageInterface::class);
        $storage
            ->expects(self::once())
            ->method('current')
            ->willThrowException(new \RuntimeException('storage failed'));
        $container = $this->compileWithMonolog($storage);
        $logger = $container->get(LoggerInterface::class);
        $handler = $container->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);

        $logger->info('application record');

        self::assertCount(1, $handler->getRecords());
        $record = $handler->getRecords()[0] ?? self::fail('missing application record');
        self::assertSame('application record', $record->message);
        self::assertSame([], $record->extra);
    }

    /** @throws \Throwable */
    private function compileWithMonolog(?ContextStorageInterface $storage = null): ContainerBuilder
    {
        return $this->compile(configure: static function (ContainerBuilder $container) use ($storage): void {
            $container->removeDefinition('logger');
            $monolog = new MonologBundle();
            $extension = $monolog->getContainerExtension();
            self::assertNotNull($extension);
            $container->registerExtension($extension);
            $container->loadFromExtension('monolog', ['handlers' => ['test' => ['type' => 'test']]]);

            $monolog->build($container);

            if ($storage !== null) {
                $container->register(ContextStorageInterface::class)->setSynthetic(true);
                $container->set(ContextStorageInterface::class, $storage);
            }
        });
    }
}
