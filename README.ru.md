# 🧵 Telemetry Weaver

**OpenTelemetry для Symfony-приложений, PHP-процесс которых живёт дольше запроса.**

[English](README.md) · Русский

PHP 8.4+ · Symfony 8.1+ · OpenTelemetry PHP SDK · MIT

Telemetry Weaver подключает трассировку, метрики и логи к Symfony и берёт на себя две вещи,
которые становятся сложными, когда процесс не заканчивается вместе с запросом: **не пустить
контекст одной единицы работы в следующую** и **не дать телеметрии тратить время приложения**.
Сэмплирование, propagation, агрегация и OTLP остаются за официальным SDK.

## Чем силён

- **Изоляция контекста в worker-режиме.** Запрос, сообщение и команда — явные границы
  выполнения. Спаны, активации контекста и измерения, созданные Weaver, закрываются на границе;
  незавершённая работа сбрасывается, а не достаётся следующему запросу. Это устройство пакета,
  а не опция: FrankenPHP, RoadRunner и `messenger:consume` — основная цель, процесс на запрос —
  частный простой случай.
- **Сбои остаются внутри телеметрии.** Упавшая инструментация не подменяет возвращаемое
  значение и никогда не подменяет исходное исключение бизнес-логики. Всё, на чём пакет
  спотыкается, попадает с ограничением частоты в один канал Monolog — вместе с диагностикой
  самого SDK.
- **Экспорт не может забрать запрос себе.** Один дедлайн на всю границу выполнения, сколько бы
  сигналов ни пришлось отправить. Коллектор, который отвалился по таймауту, пропускается на
  время cooldown; зависший коллектор не съедает время, нужное здоровому. Синхронные ретраи
  выключены по умолчанию: в PHP это sleep внутри воркера.
- **Публичный API без типов трассировки, контекста и SDK OpenTelemetry.** `Telemetry`,
  `Operation`, `Span` и `Metrics` достаточно, чтобы трассировать и измерять бизнес-операцию, не
  разбираясь в Context, scope и экспортёрах. `Metrics` намеренно возвращает собственные
  инструменты метрик OpenTelemetry, так что полный API метрик под рукой, когда он нужен.
- **Инструментация, знающая Symfony.** HTTP-сервер и клиент, Doctrine, Messenger, Console,
  Cache, Serializer, Mailer, Scheduler, Monolog и PHP-рантайм — включаются compiler pass'ами
  только тогда, когда компонент действительно установлен.
- **Осторожные значения по умолчанию.** Текст SQL, IP клиента, идентификатор пользователя и
  темы писем — opt-in, каждое отдельным ключом. Атрибуты спанов никогда не копируются в метки
  метрик: включение любого из них меняет содержимое трейса, но не количество временных рядов.
- **Заменяемо там, где это важно.** Sampler, генератор id, span processor'ы, metric views,
  экспортёры, транспорты и целые провайдеры заменяются через Symfony DI. Идентификаторы
  сервисов проверяются при компиляции контейнера, а не на первом запросе.

## Установка

```bash
composer require nmspaced/telemetry-weaver open-telemetry/exporter-otlp symfony/http-client nyholm/psr7
```

```php
// config/bundles.php
return [
    // ...
    Nmspaced\TelemetryWeaver\TelemetryWeaverBundle::class => ['all' => true],
];
```

Необязательная инструментация включается вместе со своим компонентом: Doctrine требует DBAL 4,
экспорт логов по OTLP — Monolog и MonologBundle, `user.roles` на серверном спане —
`symfony/security-core`. Для OTLP через gRPC добавьте `open-telemetry/transport-grpc` и
`ext-grpc`.

## Быстрый старт

SDK настраивается переменными окружения, пакет — YAML. Рабочий минимум:

```dotenv
OTEL_SERVICE_NAME=orders-api
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:4318

# Провайдеры создаёт Weaver; SDK не должен создавать второй комплект.
OTEL_PHP_AUTOLOAD_ENABLED=false
```

```yaml
# config/packages/open_telemetry.yaml
open_telemetry:
    enabled: true
```

Это полноценная конфигурация. Инструментируется всё, ни один чувствительный захват не включён,
бюджет экспорта — одна секунда на границу без ретраев.

> **PHP-FPM и `FRANKENPHP_RESET_KERNEL`: метрики по умолчанию выключены.** Эти рантаймы на каждый
> запрос строят новый конвейер, поэтому метрики можно экспортировать только как **delta**
> (`runtime.request_metrics.mode: delta`). Бэкенду в стиле Prometheus тогда нужен процессор
> коллектора `deltatocumulative`, причём развёрнутый так, чтобы все точки одного потока попадали в
> один и тот же экземпляр. Трейсы и логи работают сразу. См.
> [Метрики конвейеров «на запрос»](docs/ru/configuration.md#экспортируйте-метрики-из-рантайма-процесс-на-запрос-fpm-frankenphp_reset_kernel)
> и готовую [конфигурацию коллектора](config/examples/collector.yaml).

Отправить настоящий трейс в OTLP-приёмник за десять минут: [Быстрый старт](docs/ru/getting-started.md).
Перед продакшеном прочитайте [Конфигурацию](docs/ru/configuration.md): сэмплирование, бюджет
flush, метрики конвейеров «на запрос» и идентичность воркера — четыре решения, которые стоит принять
осознанно.

## Встроенная инструментация

| Компонент | Спаны | Метрики |
|---|---|---|
| HTTP-сервер | `{method} {route}`, kind server, шаблон маршрута, статус | `http.server.request.duration`, размеры тела запроса и ответа |
| HTTP-клиент | `{method}`, контекст передаётся вызываемому сервису | `http.client.request.duration`, размеры тела запроса и ответа |
| Doctrine DBAL | спаны запросов со сводкой SQL, границы транзакций | `db.client.operation.duration` |
| Messenger | спаны dispatch, send и process; контекст едет с сообщением | отправлено, получено, `messaging.process.duration` |
| Console | один спан на команду, код выхода и ошибки | `console.command.duration`, по умолчанию выкл |
| Cache | один спан на операцию пула | `cache.operation.duration`, `cache.lookup.count` с hit/miss |
| Serializer | спаны сериализации внутри существующего трейса | `serializer.operation.duration`, по умолчанию выкл |
| Mailer | один спан на отправку транспортом | `mailer.send.duration`, по умолчанию выкл |
| Scheduler | спаны запланированных задач внутри обработки сообщения | `scheduler.task.duration`, по умолчанию выкл |
| Monolog | `trace_id`/`span_id` в каждой записи, опциональный экспорт логов по OTLP | — |
| PHP-рантайм | — | `php.memory.usage`, `php.worker.uptime` по воркерам |

Телеметрия следует семантическим соглашениям `1.44.0`. Ключи каждого компонента, значения по
умолчанию и причины за ними: [Инструментация](docs/ru/instrumentation.md).

## Трассировка своего кода

`Telemetry` доступен через autowiring.

```php
use Nmspaced\TelemetryWeaver\Api\Span;
use Nmspaced\TelemetryWeaver\Api\Telemetry;

final readonly class OrderWorkflow
{
    public function __construct(private Telemetry $telemetry) {}

    public function place(string $orderId, \Closure $work): mixed
    {
        return $this->telemetry->trace('order.place', function (Span $span) use ($orderId, $work): mixed {
            $span->attribute('app.order.id', $orderId);

            return $work();
        });
    }
}
```

Колбэк выполняется ровно один раз. Спан закрывается на выходе, включая выход по исключению, и
сбой телеметрии не становится сбоем приложения.

Чтобы трассировать и измерять одну и ту же операцию, опишите её один раз. Инструмент создаётся
однократно — инструменты различаются по имени — и передаётся каждой операции, которую он
измеряет:

```php
$this->duration = $telemetry->metrics()->duration(
    'app.payment.duration',
    unit: DurationUnit::Seconds,
    boundaries: [0.01, 0.05, 0.1, 0.5, 1, 5],
);

$accepted = $telemetry
    ->operation('payment.charge')
    ->duration($this->duration, attributes: ['app.payment.method' => 'card'])
    ->run(function (OperationContext $context) use ($charge): bool {
        $accepted = $charge();

        if (!$accepted) {
            $context->fail('payment.declined');
        }

        return $accepted;
    });
```

Часами владеет операция, поэтому измерение соотнесено со своим спаном и не может быть запущено
не в тот момент. `fail()` помечает спан и гистограмму сразу, не выдумывая исключение. Атрибуты
метрик намеренно отделены от атрибутов спана: идентификатор заказа уместен в трейсе, а в метке
метрики это новый временной ряд.

Также в API: все инструменты OpenTelemetry через `metrics()`, `Operation::baggage()` для
значений, которые должны доехать до вызываемых сервисов, `Span::traceId()`, чтобы показать
trace id на странице ошибки, и `ActiveTrace` — для кода, которому спан передать нельзя вовсе
(процессор Monolog, мидлвара Doctrine): он читает идущий трейс как три значения, а не как
хэндл. См. [API приложения](docs/ru/getting-started.md#api-приложения).

## Жизненный цикл в воркере

Weaver различает PHP-процесс, контейнер Symfony, одну единицу работы и одну операцию — и
сливает их только там, где их действительно сливает рантайм:

```text
FPM / процесс на запрос             Общий воркер (FrankenPHP, RoadRunner, Messenger)
───────────────────────────        ────────────────────────────────────────────────
запрос                             воркер стартовал
  └─ работа                          ├─ запрос A ───── flush
  └─ terminate ──── shutdown         ├─ запрос B ───── flush
                                     └─ воркер остановлен ─ shutdown
```

Flush опустошает конвейер, который продолжает жить; shutdown его завершает. Что именно делает
граница, определяет runtime mode Symfony, а не `PHP_SAPI`. Всё, чем Weaver владеет в этой
единице работы, освобождается на границе в обоих случаях. См.
[Архитектуру и жизненный цикл](docs/ru/architecture.md).

## Тестирование

`InMemoryTelemetry` — публичный тестовый дубль: без контейнера и без коллектора.

```php
use Nmspaced\TelemetryWeaver\Testing\InMemoryTelemetry;

$telemetry = InMemoryTelemetry::create();

try {
    (new OrderWorkflow($telemetry))->place('order-42', static fn(): bool => true);

    self::assertSame('order.place', $telemetry->spans()[0]->getName());
    self::assertNull($telemetry->activeTrace());  // операция ничего не оставила активным
} finally {
    $telemetry->shutdown();
}
```

## Документация

- [Быстрый старт](docs/ru/getting-started.md) — первый трейс, первая метрика, API приложения
- [Конфигурация](docs/ru/configuration.md) — окружение, бюджет, сэмплирование, FPM, идентичность воркера
- [Инструментация](docs/ru/instrumentation.md) — по компонентам: что даёт и чего стоит
- [Архитектура и жизненный цикл](docs/ru/architecture.md) — владение, границы, слои
- [Устойчивость экспорта](docs/ru/export-resilience.md) — бюджет flush и от чего он защищает
- [Кастомизация SDK](docs/ru/sdk-customization.md) — замена частей конвейера
- [`config/minimal_config.yaml`](config/minimal_config.yaml) · [`config/example_config.yaml`](config/example_config.yaml) — все ключи со значениями по умолчанию

Лицензия **MIT**.
