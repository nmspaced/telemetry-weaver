# 🧵 Telemetry Weaver

**OpenTelemetry для Symfony-приложений, где PHP-процесс может переживать один запрос.**

[English](README.md) · Русский

Telemetry Weaver добавляет traces, metrics и logs для **FrankenPHP, RoadRunner, Symfony Messenger и обычного request-based PHP**, но главным объектом архитектуры считает lifecycle. Пакет изолирует контекст запросов и сообщений в долгоживущих workers и ограничивает время, которое observability может отнять у приложения.

**PHP 8.4+ · Symfony 8.1+ · OpenTelemetry PHP SDK · MIT**

## Почему Telemetry Weaver

- **Worker-first lifecycle.** HTTP request, Messenger message и Console command — явные execution boundaries. State одной работы не должен попадать в следующую.
- **Fail-open instrumentation.** Ошибка telemetry не меняет return value и не подменяет исходное исключение бизнес-кода.
- **Ограниченная стоимость экспорта.** Batch-очереди, один deadline на boundary, budget по destination и cooldown ограничивают влияние медленного или недоступного Collector.
- **OpenTelemetry-native.** Sampling, propagation, aggregation и OTLP остаются в официальном PHP SDK; bundle добавляет Symfony lifecycle, instrumentation и resilience.
- **Консервативный сбор данных.** SQL text, client IP и mail subject включаются явно. Span attributes не копируются в metric labels.
- **Заменяемый SDK pipeline.** HTTP/gRPC transport, exporter и provider можно подменить через Symfony DI, если стандартной модели недостаточно.

## Установка

Для OTLP через HTTP/protobuf:

```bash
composer require nmspaced/telemetry-weaver open-telemetry/exporter-otlp symfony/http-client nyholm/psr7
```

Зарегистрируйте bundle:

```php
// config/bundles.php
return [
    // ...
    Nmspaced\TelemetryWeaver\TelemetryWeaverBundle::class => ['all' => true],
];
```

Опциональные интеграции включаются при наличии зависимостей. Doctrine instrumentation требует DBAL 4. Для OTLP log export нужны Monolog и Symfony MonologBundle.

Для OTLP/gRPC дополнительно установите gRPC transport OpenTelemetry и `ext-grpc`:

```bash
composer require open-telemetry/transport-grpc
```

## Рекомендуемая production-топология

Предпочтительный вариант — **локальный или близкий OpenTelemetry Collector / Grafana Alloy**, который берёт на себя удалённые очереди, retries, credentials и маршрутизацию по backend'ам:

```text
PHP worker
   │ короткий и ограниченный OTLP handoff
   ▼
Collector / Alloy на том же host, sidecar или в локальной сети
   │ queue / retry / batching / routing
   ▼
удалённые observability backend'ы
```

Это соответствует agent deployment pattern OpenTelemetry Collector. Telemetry Weaver защищает application-side handoff, но специально **не является durable delivery queue**.

Хороший старт для worker с локальным OTLP/HTTP receiver:

```dotenv
OTEL_SERVICE_NAME=orders-api
OTEL_RESOURCE_ATTRIBUTES=service.namespace=backend,deployment.environment.name=production

# Providers создаёт Telemetry Weaver. Второй SDK bootstrap не нужен.
OTEL_PHP_AUTOLOAD_ENABLED=false
OTEL_PROPAGATORS=tracecontext,baggage

OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=none

OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318
OTEL_EXPORTER_OTLP_TIMEOUT=500

# Сохранять решение родителя и сэмплировать 10% новых root traces.
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1

# Batch processor: Weaver выключает auto-flush и дренирует очередь на boundaries.
OTEL_PHP_TRACES_PROCESSOR=batch
OTEL_BSP_SCHEDULE_DELAY=1000
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512

# Используется как default cadence для metric flush на boundaries.
OTEL_METRIC_EXPORT_INTERVAL=15000
```

Минимальная Symfony-конфигурация:

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    instrumentation:
        http_client:
            # Укажите hostname, к которому реально обращается приложение.
            excluded_hosts: ['127.0.0.1', 'localhost', 'otel-collector', 'alloy']

    sdk:
        export:
            flush_timeout_ms: 1000
            failure_cooldown_ms: 30000
            max_retries: 0
```

Это стартовые значения, а не универсальные лимиты. Sampling и размеры очередей нужно подбирать под объём **одного worker**. Если Collector локальный, OTLP timeout порядка 250–500 ms обычно уже достаточно большой и хорошо выявляет нездоровый local handoff.

Примеры:

- [worker OTLP/HTTP environment](config/examples/worker.env)
- [FPM environment](config/examples/fpm.env)
- [gRPC environment](config/examples/grpc.env)
- [раздельные endpoints по signals](config/examples/split-endpoints.env)
- [worker YAML](config/examples/worker.yaml)
- [FPM YAML](config/examples/fpm.yaml)

Перед включением request metrics в FPM или прямым экспортом в удалённый backend прочитайте [production configuration](docs/ru/production-configuration.md).

## Lifecycle за минуту

Telemetry Weaver различает lifetime PHP process, Symfony container и отдельной единицы работы:

```text
PHP-FPM
request
  └─ provider lifecycle
       └─ shutdown

FrankenPHP / RoadRunner shared worker
worker
  ├─ request A ─ forceFlush
  ├─ request B ─ forceFlush
  └─ worker shutdown ─ shutdown

Messenger
worker
  ├─ message A ─ forceFlush
  ├─ message B ─ forceFlush
  └─ worker shutdown ─ shutdown
```

`forceFlush()` дренирует долгоживущий pipeline, но не уничтожает его. `shutdown()` — terminal operation. Owned spans/scopes/measurements очищаются на execution boundary, поэтому следующий request или message не должен унаследовать state предыдущего.

Подробнее: [архитектура и lifecycle](docs/ru/architecture.md).

## Отказоустойчивый экспорт

Default OTLP path намеренно opinionated:

- spans и logs попадают в очередь вместо network export из `span->end()` / log emission;
- у всей execution boundary один `flush_timeout_ms` deadline;
- время делится между **destinations** (`scheme://host:port`), а не вслепую между signals;
- traces/logs/metrics на одном Collector разделяют один failure domain;
- destination, исчерпавший долю, больше не получает новый полный timeout в той же boundary;
- независимые destinations продолжают получать шанс на export;
- неиспользованное время быстрого destination остаётся доступно следующим;
- слишком маленький остаток budget не тратится на заведомо бессмысленный network call;
- cooldown начинается только после достаточно убедительного timeout;
- synchronous retries на boundary по умолчанию выключены.

Пример:

```text
traces ─┐
logs   ─┴─→ collector-a:4318  (hung)

metrics ──→ collector-b:4318  (healthy)
```

Зависший Collector не получает новый полный timeout для каждого signal и не может бесконечно вытеснять независимый metrics destination.

Подробнее: [export resilience и budgets](docs/ru/export-resilience.md).

## Встроенная instrumentation

| Компонент | Telemetry |
|---|---|
| HTTP server | server spans, route templates, duration и HTTP metrics |
| Symfony HttpClient | client spans, propagation, duration, lazy responses и streaming |
| Doctrine DBAL | query spans, query summary, duration, transaction boundaries и errors |
| Messenger | dispatch/send/process spans, propagation и send/process metrics |
| Console | command spans, duration и exit code; worker-команды трассируются по сообщениям |
| Cache | операции с pool, duration и hit/miss measurements |
| Serializer | serialization operations и duration |
| Mailer | transport send spans и duration |
| Scheduler | выполнение scheduled task внутри message processing |
| Monolog | trace/span correlation и optional OTLP log export |
| PHP runtime | memory и uptime долгоживущего worker |

Bundle использует Semantic Conventions schema `1.44.0`. Потенциально чувствительные значения вроде SQL text, client IP и mail subject по умолчанию выключены там, где пакет даёт такой выбор.

Подробнее: [тонкости instrumentation](docs/ru/instrumentation.md).

## Application API

`Telemetry` доступен через Symfony autowiring.

### Trace бизнес-операции

```php
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    public function __construct(private Telemetry $telemetry) {}

    public function place(string $orderId, \Closure $work): mixed
    {
        return $this->telemetry->trace(
            'order.place',
            function (Span $span) use ($orderId, $work): mixed {
                $span->attribute('app.order.id', $orderId);

                return $work();
            },
        );
    }
}
```

Callback выполняется ровно один раз. Span закрывается автоматически, в том числе при исключении; telemetry failure не подменяет исходный результат приложения.

### Trace и duration одной операции

```php
use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class PaymentTelemetry
{
    private Duration $duration;

    public function __construct(private Telemetry $telemetry)
    {
        $this->duration = $telemetry->metrics()->duration(
            'app.payment.duration',
            unit: DurationUnit::Seconds,
            boundaries: [0.01, 0.05, 0.1, 0.5, 1, 5],
        );
    }

    /** @param \Closure(): bool $charge */
    public function charge(\Closure $charge): bool
    {
        return $this->telemetry
            ->operation('payment.charge')
            ->duration($this->duration, attributes: ['app.payment.method' => 'card'])
            ->run(function (OperationContext $context) use ($charge): bool {
                $accepted = $charge();
                if (!$accepted) {
                    $context->fail('payment.declined');
                }

                return $accepted;
            });
    }
}
```

`fail()` отмечает ошибку и в span, и в measurement без искусственного exception. Span attributes и metric attributes разделены специально: ID, payload и другие неограниченные значения не стоит помещать в metric labels.

Также доступны counters, histograms, observable gauges/up-down counters, `currentSpan()` и `TelemetryFactory::scope()`.

## Подмена transport, exporter или provider

Defaults безопасны, но не обязательны. Через Symfony DI можно заменить всё более глубокие части signal pipeline:

```text
default
provider → resilient exporter → budget-aware OTLP transport

transport override
provider → resilient exporter → ваш transport

exporter override
provider → resilient wrapper → ваш exporter

provider override
ваш provider (Weaver только принимает его в boundary lifecycle)
```

HTTP и gRPC transport families подменяются независимо. Чем глубже override, тем меньше гарантий стандартного Weaver pipeline остаётся у пакета.

См. [SDK customization](docs/ru/sdk-customization.md) с матрицей гарантий и примерами.

## Тестирование

Для application tests используйте `InMemoryTelemetry`; Collector не требуется:

```php
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;

$telemetry = InMemoryTelemetry::create();

try {
    $workflow = new OrderWorkflow($telemetry);
    $result = $workflow->place('order-42', static fn(): bool => true);

    assert($result === true);
    assert($telemetry->spans()[0]->getName() === 'order.place');
} finally {
    $telemetry->shutdown();
}
```

## Документация

- [Production configuration](docs/ru/production-configuration.md)
- [Архитектура и lifecycle](docs/ru/architecture.md)
- [Export resilience и budgets](docs/ru/export-resilience.md)
- [Тонкости instrumentation](docs/ru/instrumentation.md)
- [SDK customization](docs/ru/sdk-customization.md)
- [Минимальная конфигурация bundle](config/minimal_config.yaml)
- [Полный reference конфигурации](config/example_config.yaml)
- [OpenTelemetry PHP](https://opentelemetry.io/docs/languages/php/)

Лицензия — **MIT**.
