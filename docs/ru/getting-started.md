# Быстрый старт

К концу этой страницы Symfony-приложение отправляет настоящие трейсы и метрики в коллектор, за
которым можно наблюдать, а ваш собственный код создаёт свой спан и свою гистограмму.

Нужны PHP 8.4, приложение на Symfony 8.1 и Docker для коллектора.

## 1. Поднимите коллектор, в который можно смотреть

Телеметрии нужно куда-то уходить. Для первого запуска коллектор, печатающий всё полученное,
полезнее настоящего бэкенда.

```yaml
# otel-collector.yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318

exporters:
  debug:
    verbosity: detailed

service:
  pipelines:
    traces:
      receivers: [otlp]
      exporters: [debug]
    metrics:
      receivers: [otlp]
      exporters: [debug]
```

```bash
docker run --rm -p 4318:4318 \
    -v "$PWD/otel-collector.yaml:/etc/otelcol-contrib/config.yaml" \
    otel/opentelemetry-collector-contrib:latest
```

Оставьте его в отдельном терминале — всё, что экспортирует приложение, появится там.

## 2. Установите пакет

```bash
composer require nmspaced/telemetry-weaver open-telemetry/exporter-otlp symfony/http-client nyholm/psr7
```

Последние два закрывают `psr/http-client-implementation` и `psr/http-factory-implementation`,
которые нужны OTLP/HTTP-экспортёру. Если в приложении уже есть PSR-18-клиент и PSR-17-фабрика,
они уже закрыты.

```php
// config/bundles.php
return [
    // ...
    Nmspaced\TelemetryWeaver\TelemetryWeaverBundle::class => ['all' => true],
];
```

## 3. Направьте SDK на коллектор

Конфигурация разделена надвое, и это разделение стоит понимать с самого начала:

| | Отвечает за | Примеры |
|---|---|---|
| переменные `OTEL_*` | SDK и экспорт | endpoint, протокол, заголовки, sampler, размеры очередей, интервалы |
| `open_telemetry.yaml` | что инструментируется и что записывается | какие компоненты, какие атрибуты, бюджет flush |

Ни один ключ пакета не дублирует переменную `OTEL_*`.

```dotenv
# .env.local
OTEL_SERVICE_NAME=orders-api
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318

# Трейсы и метрики включены, логи остаются в существующем конвейере.
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=none

# Локально трассируем всё. В продакшене число другое — см. «Конфигурацию».
OTEL_TRACES_SAMPLER=always_on

# Провайдеры создаёт пакет. Автозагрузчик SDK держите выключенным, иначе получится два
# конвейера, экспортирующих одну и ту же телеметрию.
OTEL_PHP_AUTOLOAD_ENABLED=false
```

Самому пакету ничего, кроме включённости, не нужно:

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    enabled: true
```

Значения по умолчанию инструментируют каждый установленный компонент, не записывают ничего,
что идентифицирует человека, и дают всему экспорту одну секунду на границу.

## 4. Посмотрите первый трейс

Сделайте один запрос к приложению — любой маршрут.

```bash
curl -s http://localhost:8000/ > /dev/null
```

В терминале коллектора появится спан, названный по маршруту — например, `GET /orders/{id}`, — с
`http.request.method`, `http.route` и `http.response.status_code`, и гистограмма
`http.server.request.duration`. Любой запрос к БД, HTTP-вызов или обращение к кешу, сделанные
внутри, окажутся дочерними спанами.

Если ничего не пришло:

- **коллектор не видит подключения вообще** — неверный endpoint или приложение не достаёт до
  него из своего контейнера. Из PHP в контейнере `127.0.0.1` — это сам контейнер, а не хост.
- **приложение пишет ошибку экспорта** — пакет сообщает о своих сбоях в канал Monolog
  `open_telemetry` вместе с диагностикой SDK. Причина будет там.
- **тишина везде** — проверьте, что `OTEL_PHP_AUTOLOAD_ENABLED=false` не выключил больше
  задуманного и что `open_telemetry.enabled` не равен `false` в текущем окружении. Поставляемый
  `example_config.yaml` выключает пакет в `when@test`.

## 5. Трассируйте собственную операцию

Инструментация фреймворка показывает, что делал Symfony. Ваши спаны показывают, что делало
приложение. `Telemetry` доступен через autowiring:

```php
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    public function __construct(private Telemetry $telemetry) {}

    public function place(string $orderId): Order
    {
        return $this->telemetry->trace('order.place', function (Span $span) use ($orderId): Order {
            $span->attribute('app.order.id', $orderId);

            return $this->doPlace($orderId);
        });
    }
}
```

Вызовите это из контроллера — и в следующем трейсе под серверным спаном появится `order.place`,
а под ним, в свою очередь, выполненные запросы.

Колбэк выполняется ровно один раз, его возвращаемое значение ваше. Спан закрывается на выходе —
в том числе когда колбэк бросает исключение: оно записывается на спан и пробрасывается дальше
без изменений.

## 6. Измерьте её

Спан рассказывает про один запрос. Гистограмма — про все.

```php
use Nmspaced\TelemetryWeaver\Api\Duration;
use Nmspaced\TelemetryWeaver\Api\DurationUnit;
use Nmspaced\TelemetryWeaver\Api\OperationContext;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    private Duration $duration;

    public function __construct(private Telemetry $telemetry)
    {
        // Один раз на сервис: инструменты различаются по имени, второй запрос — впустую.
        $this->duration = $telemetry->metrics()->duration(
            'app.order.place.duration',
            unit: DurationUnit::Seconds,
            boundaries: [0.01, 0.05, 0.1, 0.5, 1, 5],
        );
    }

    public function place(string $orderId): Order
    {
        return $this->telemetry
            ->operation('order.place')
            ->attributes(['app.order.id' => $orderId])
            ->duration($this->duration, attributes: ['app.order.channel' => 'web'])
            ->run(function (OperationContext $context) use ($orderId): Order {
                $order = $this->doPlace($orderId);

                if ($order->isOnHold()) {
                    $context->fail('order.on_hold');
                }

                return $order;
            });
    }
}
```

Два набора атрибутов — намеренно. `attributes()` описывает спан: идентификатор заказа уместен
там. `duration(..., attributes: ...)` описывает измерение, и каждое новое значение здесь — новый
временной ряд: у канала пять значений, у идентификатора заказа — миллионы.

`fail()` помечает неуспешный исход сразу на обоих сигналах, не выдумывая исключение. Исключение,
вылетевшее позже, всё равно будет записано и не перезапишет указанную вами причину.

## API приложения

Всё, что нужно приложению, лежит в `Nmspaced\TelemetryWeaver\Api`, и ни один тип оттуда не
принадлежит OpenTelemetry — кроме самих инструментов метрик.

### `Telemetry`

| Метод | Назначение |
|---|---|
| `trace(string $name, \Closure $work, array $attributes = []): mixed` | один спан вокруг одного колбэка, короткая форма |
| `operation(string $name): Operation` | описание, которое собирается до запуска |
| `metrics(): Metrics` | инструменты |

### `Operation`

Неизменяемое описание: каждый вызов возвращает новое, и ничего не начинается до `run()` или
`start()`.

| Метод | Назначение |
|---|---|
| `attributes(array $attributes)` | атрибуты спана |
| `kind(SpanKind $kind)` | `Internal`, `Server`, `Client`, `Producer`, `Consumer` |
| `duration(Duration $duration, array $attributes = [])` | измерять эту операцию этим инструментом |
| `baggage(array $entries)` | значения, которые доедут до каждого вызванного сервиса |
| `run(\Closure $work): mixed` | запустить; возвращает результат колбэка |
| `start(): RunningOperation` | для работы, не укладывающейся в один колбэк |

`start()` возвращает операцию, которую вы обязаны сами завершить через `finish()` или
`abandon()` — в том же контексте выполнения, где начали. Предпочитайте `run()`: о нём нельзя
забыть.

### `OperationContext`, внутри колбэка

| Метод | Назначение |
|---|---|
| `span(): Span` | обогатить спан |
| `fail(string $type)` | пометить сбой на спане и на измерении |
| `metricAttributes(array $attributes)` | атрибуты, известные только после выполнения работы |
| `baggage(): array` | что передал вызывающий, плюс добавленное этой операцией |

### `Span`

`attribute()`, `attributes()`, `rename()`, `event()`, `recordException()`, `fail()`,
`isRecording()` — и `traceId()` / `spanId()`, возвращающие строчный hex формата W3C trace
context либо `null`, когда трассировки нет. Они существуют ради двух вещей: показать trace id на
странице ошибки и передать его системе, которая сопоставляет по id. Спросить у фасада, какой
трейс идёт прямо сейчас, намеренно нельзя: операция знает свой.

### `Metrics`

`duration()` возвращает собственный тип пакета `Duration` для `Operation::duration()`. Все
остальные методы возвращают нативные инструменты OpenTelemetry: `counter()`, `upDownCounter()`,
`histogram()`, `gauge()`, `observableCounter()`, `observableGauge()`,
`observableUpDownCounter()`.

Observable-инструменты читаются во время экспорта — на том выполнении, которое окажется на
границе flush. Их колбэк обязан быть дешёвым и не трогать ничего, что существует только во время
запроса. Держите возвращённый хэндл столько, сколько измерения должны публиковаться: его
освобождение отцепляет колбэк.

### Baggage

```php
$telemetry
    ->operation('checkout')
    ->baggage(['tenant.id' => $tenantId])
    ->run(function (OperationContext $context): void {
        // Каждый исходящий вызов отсюда несёт tenant.id, а потребитель на той стороне
        // читает его обратно через $context->baggage().
    });
```

Baggage — не атрибут. Атрибут описывает спан, на котором стоит, и дальше не идёт; baggage
добавляется в исходящие заголовки каждого запроса операции, то есть покидает процесс и попадает
в сервисы, которые могут быть не вашими. Кладите туда тенанта или когорту фича-флага — и никогда
токен или персональный идентификатор: отозвать отправленное нельзя. Значения, прочитанные через
`baggage()`, пришли из другого сервиса — относитесь к ним как к входным данным.

### Собственный scope

Телеметрия приложения приходит под instrumentation scope `app`. Библиотека, поставляющая свою
телеметрию, может назвать свой:

```php
use Nmspaced\TelemetryWeaver\Api\TelemetryFactory;

$telemetry = $factory->scope('acme/billing', version: '2.1.0');
```

## 7. Проверьте всё это без коллектора

`InMemoryTelemetry` реализует тот же API и записывает всё в память:

```php
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;

$telemetry = InMemoryTelemetry::create();

try {
    $order = (new OrderWorkflow($telemetry))->place('order-42');

    self::assertSame('order.place', $telemetry->spans()[0]->getName());
    self::assertNull($telemetry->activeTrace());
} finally {
    $telemetry->shutdown();
}
```

`activeTrace()` — та проверка, которую проваливает ошибка владения: после единицы работы ничего
не должно оставаться активным. Метод есть только у тестового дубля — публичный API не даёт
читать ambient-состояние, и именно это здесь проверяется.

`measurements()` возвращает дельта-измерения с прошлого чтения — для проверок по метрикам.
`reset()` очищает и то, и другое между кейсами.

## Дальше

- [Конфигурация](configuration.md) — что поменять перед продакшеном: сэмплирование, бюджет
  flush, локальный коллектор, request-метрики под FPM, идентичность воркера.
- [Инструментация](instrumentation.md) — что даёт каждый компонент и какие захваты выключены,
  пока вы не попросите.
- [Архитектура и жизненный цикл](architecture.md) — зачем всё это воркеру.
