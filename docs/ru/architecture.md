# Архитектура и lifecycle

Telemetry Weaver — это lifecycle-слой между Symfony и OpenTelemetry PHP SDK. Основная сложность long-running PHP не в том, чтобы открыть span, а в том, **кто владеет state и когда этот state обязан перестать существовать**.

## Lifetimes не совпадают

Bundle различает четыре lifetime:

1. PHP execution / worker process;
2. Symfony kernel и service container;
3. один request, message или command;
4. одна telemetry operation.

Shared worker может сохранять первые два между тысячами запросов. Поэтому request-specific OpenTelemetry context нельзя оставлять «до конца процесса».

## Runtime profile

HTTP lifecycle следует Symfony-параметрам `kernel.runtime_mode.web` и `kernel.runtime_mode.worker`, которые разрешаются из `APP_RUNTIME_MODE`:

| Runtime | Модель | Finalization provider |
|---|---|---|
| request-based/FPM (`web=1&worker=0`) | pipeline принадлежит одному request | `shutdown()` на terminate |
| shared HTTP worker (`worker=1`) | provider переживает requests | `forceFlush()` на boundaries, `shutdown()` при завершении процесса |
| reset-kernel worker (`worker=2`) | PHP execution живёт дальше, container нет | provider shutdown на request, resource identity worker остаётся стабильной |
| Console/Messenger | boundaries задают events | flush по lifecycle command/message и final shutdown при выходе процесса |

Не нужно определять эту модель по `PHP_SAPI`. Если нестандартный RoadRunner runtime сам не выставляет корректный Symfony runtime mode, настройте `APP_RUNTIME_MODE`, чтобы shared HTTP worker был виден как `web=1&worker=1`.

## Ownership: owned и ambient context

Instrumentation может увидеть уже активный span. Это не означает, что она им владеет.

Telemetry Weaver завершает и detach'ит только созданный им state. Это защищает от двух типичных worker-багов:

- закончить span другого слоя;
- оставить activation scope request активным для следующего request.

Явная `Operation` владеет своими span, scope и measurements до `finish()` или `abandon()`.

## Reset и незавершённая работа

На execution boundary bundle освобождает state завершившейся работы. Незаконченные operations abandon'ятся, а не переносятся в следующую единицу работы.

Fallback cleanup в деструкторе/PHP shutdown намеренно консервативен: он отсоединяет context, но не обещает доставить незавершённую telemetry после fatal error или принудительного завершения процесса.

## Provider registry и lazy creation

Providers создаются лениво. Finalizer работает только с реально созданными providers; завершение request не должно создавать MeterProvider только ради немедленного shutdown.

Custom provider из Symfony DI всё равно принимается в provider registry, поэтому Weaver может вызвать boundary `forceFlush()` / `shutdown()`. Всё, что находится *внутри* custom provider, уже не оборачивается и не контролируется.

## Resource identity

Long-lived producers получают `service.instance.id` через SDK `ServiceInstance` detector. Значение стабильно для конкретного PHP execution и различает workers, у которых совпадают остальные service/resource attributes.

Явно заданный `service.instance.id` имеет приоритет над generated default. Если задаёте его сами, он должен действительно идентифицировать одного concurrent producer. Не используйте один и тот же hostname как `service.instance.id` для всех worker processes.

Request-based/FPM pipeline специально не получает случайный instance id на каждый request. Request metrics, если их включить, требуют writer identity — например `process.pid` вместе с host/container identity или явно уникальный service instance.

## Metric temporality

Telemetry Weaver выбирает temporality по типу инструмента вместо одного scalar значения для всех instruments.

Для preference `delta`:

| Instrument | Temporality |
|---|---|
| Counter | DELTA |
| Histogram | DELTA |
| ObservableCounter | DELTA |
| UpDownCounter | CUMULATIVE |
| ObservableUpDownCounter | CUMULATIVE |
| Gauge-like state | state / cumulative reader selection |

Это важно для state вроде worker memory или активной работы: downstream не должен зависеть от вечно сохранённого delta-baseline только для восстановления текущего значения.

## OpenTelemetry остаётся SDK

Weaver не заменяет модель OpenTelemetry. Официальный SDK по-прежнему отвечает за sampling, Context, propagation, aggregation и exporter contracts. Bundle добавляет Symfony lifecycle, framework instrumentation, worker isolation и controlled export boundaries.
