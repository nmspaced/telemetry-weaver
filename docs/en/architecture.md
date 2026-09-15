# Architecture and lifecycle

Telemetry Weaver is a lifecycle layer between Symfony and the OpenTelemetry PHP SDK. The difficult part in long-running PHP is not starting a span; it is deciding **who owns state and when that state must stop existing**.

## Lifetimes are different

The bundle distinguishes four lifetimes:

1. PHP execution / worker process;
2. Symfony kernel and service container;
3. one request, message or command;
4. one telemetry operation.

A shared worker can keep the first two alive across many requests. Request-specific OpenTelemetry context therefore cannot be left to process shutdown.

## Runtime profile

The HTTP lifetime follows Symfony's `kernel.runtime_mode.web` and `kernel.runtime_mode.worker`, resolved from `APP_RUNTIME_MODE`:

| Runtime shape | Effective model | Provider finalization |
|---|---|---|
| request-based/FPM (`web=1&worker=0`) | pipeline belongs to one request | `shutdown()` on terminate |
| shared HTTP worker (`worker=1`) | provider survives requests | `forceFlush()` at boundaries, `shutdown()` at process exit |
| reset-kernel worker (`worker=2`) | PHP execution survives, container does not | provider shutdown per request, worker resource identity remains stable |
| Console/Messenger | events define boundaries | flush per command/message lifecycle, final shutdown at process exit |

Do not infer this from `PHP_SAPI`. If a custom RoadRunner runtime does not expose the correct Symfony runtime mode automatically, configure `APP_RUNTIME_MODE` so that a shared HTTP worker is visible as `web=1&worker=1`.

## Ownership: owned vs ambient context

Instrumentation may find an already active span. That does not mean it owns that span.

Telemetry Weaver tracks the objects it creates and only closes/detaches its own state. This avoids two classes of worker bugs:

- ending another layer's span;
- leaving a request's activation scope active for the next request.

An explicit `Operation` owns its span, scope and measurements until `finish()` or `abandon()`.

## Reset and abandoned work

At an execution boundary the bundle releases state owned by the completed work. Unfinished operations are abandoned rather than allowed to leak into the next unit of work.

Fallback destructor/PHP-shutdown cleanup is intentionally conservative: it detaches context; it is not a promise that unfinished telemetry will be delivered after a fatal or forced process termination.

## Provider registry and laziness

Providers are created lazily. The finalizer only touches providers that were actually instantiated; ending a request must not create a MeterProvider just to shut it down immediately.

A custom provider configured through Symfony DI is still adopted by the provider registry, so Weaver can call the appropriate boundary `forceFlush()` / `shutdown()`. Nothing *inside* a custom provider is wrapped or controlled.

## Resource identity

Long-lived producers receive a `service.instance.id` from the SDK's `ServiceInstance` detector. The value is stable for that PHP execution and distinguishes workers that otherwise publish the same service/resource attributes.

A user-provided `service.instance.id` wins over the generated default. If you set it manually, it must really identify one concurrent producer. Do not assign the same host name as `service.instance.id` to every worker process.

Request-based/FPM pipelines intentionally do not get a random instance id per request. Request metrics, when enabled, instead require a usable writer identity such as `process.pid` plus host/container identity or an explicitly unique service instance.

## Metric temporality

Telemetry Weaver uses an instrument-aware temporality selector instead of applying one scalar temporality to every instrument.

For the `delta` preference:

| Instrument | Temporality |
|---|---|
| Counter | DELTA |
| Histogram | DELTA |
| ObservableCounter | DELTA |
| UpDownCounter | CUMULATIVE |
| ObservableUpDownCounter | CUMULATIVE |
| Gauge-like state | state / cumulative reader selection |

This matters for state such as worker memory or active work: downstream should not need a permanently intact delta baseline merely to reconstruct the current value.

## OpenTelemetry remains the SDK

Weaver does not replace the OpenTelemetry model. The official SDK remains responsible for sampling, Context, propagation, aggregation and exporter contracts. The bundle contributes Symfony lifecycle, framework instrumentation, worker isolation and controlled export boundaries.
