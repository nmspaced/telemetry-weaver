# Architecture and lifecycle

Telemetry Weaver is a lifecycle layer between Symfony and the OpenTelemetry PHP SDK. Starting a
span is not the hard part in long-running PHP. Deciding **who owns a piece of state and when it
must stop existing** is.

## Four lifetimes

The bundle keeps four things apart that PHP's request model traditionally lets collapse into
one:

1. the PHP process, or worker;
2. the Symfony kernel and service container;
3. one request, message or command;
4. one telemetry operation.

In PHP-FPM, 1 and 2 end with 3, which is why request-scoped telemetry can be left to process
shutdown and usually is. In a shared worker they do not: the process and the container serve
hundreds of requests. Anything scoped to one of them that is cleaned up "at shutdown" is, in
practice, never cleaned up — it is inherited by the next request instead.

So a request, a message and a command are **execution boundaries**, and every piece of state the
bundle created for that unit of work is released there.

## Runtime profile

Which boundary behaviour applies follows Symfony's `kernel.runtime_mode.web` and
`kernel.runtime_mode.worker`, resolved from `APP_RUNTIME_MODE` — never from `PHP_SAPI`.

| Runtime shape | Mode | Effective model | Finalization |
|---|---|---|---|
| PHP-FPM, one request per PHP execution | `web=1&worker=0` | the pipeline belongs to one request | `shutdown()` on terminate |
| Shared HTTP worker | `worker=1` | the pipeline outlives requests | `forceFlush()` per request, `shutdown()` at process exit |
| Worker that rebuilds its kernel per request | `worker=2` | the process survives, the container does not | provider shutdown per request, worker identity stays stable |
| Console, Messenger | — | events define the boundaries | flush per command or message, shutdown at process exit |

FPM is not "a new OS process per request": a pool child serves many requests in turn. What ends
with the request is the PHP execution and the container built for it, which is why the pipeline
can be finalized on terminate — and why the cooldown a failing collector caused is not remembered
by the next request.

`forceFlush()` drains a pipeline that keeps living. `shutdown()` is terminal. Conflating them is
how a worker ends up with a provider that was shut down on its first request.

If a custom RoadRunner integration does not expose the runtime mode automatically, set
`APP_RUNTIME_MODE=web=1&worker=1` so a shared HTTP worker is visible as one.

## Ownership: created here, or merely found here

Instrumentation frequently finds an active span. Finding one is not owning it.

The bundle tracks the objects it created — spans, context activations, measurements — and closes
or detaches only those. Two classes of worker bug follow directly from getting this wrong, and
both have been seen in the wild:

- ending a span that belongs to another layer, which truncates a trace someone else is still
  building;
- leaving a request's context activation attached, so the next request on that worker starts
  inside the previous request's trace.

An explicit `Operation` owns its span, its activation and its measurement until `finish()` or
`abandon()`. `run()` guarantees one of the two happens, including on the exception path, which
is why it is the form to prefer.

At a boundary, work that is still unfinished is **abandoned**: its state is released without
pretending the operation completed. Abandonment is not a failure mode to avoid — it is the
correct outcome for a request that died halfway, and it is what keeps the next request clean.

The destructor and PHP-shutdown paths do the same, conservatively: they detach context. They are
not a promise that unfinished telemetry will be delivered after a fatal error or a killed
process.

## Fail-open

A telemetry failure must never become an application failure. An instrumentation that throws
does not replace a return value, and never replaces the original business exception.

This is not a `try`/`catch` sprinkled at call sites. Failures funnel through the
`Internal/Diagnostics` reporters, which rate-limit them, and the `Safe*` / `Resilient*` wrappers
exist so that the fail-open path is a type rather than a convention.

## Providers

At most one provider per signal, held by a registry the container populates. Providers are
created lazily, and finalization only touches the ones that were actually instantiated —
finishing a request must not construct a MeterProvider purely to shut it down.

Provider factories deliberately do not register SDK shutdown callbacks. Doing so exported
metrics twice, once from the callback and once from the boundary. Delivery and finalization have
exactly one owner.

A provider replaced through DI is still adopted into the registry, so its boundary
`forceFlush()` / `shutdown()` still happen. Nothing *inside* it is wrapped or controlled — see
[SDK customization](sdk-customization.md).

## Resource identity

Long-lived producers receive a `service.instance.id` from the SDK's `ServiceInstance` detector.
It is stable for that PHP execution and is what distinguishes workers that otherwise publish
identical resource attributes.

A value you set wins over the generated one, so set it only when it really identifies one
concurrent producer. The same hostname on every worker process is worse than the default.

Request-per-process pipelines deliberately do not get a random instance id per request — that
would create a new series for every request. Request metrics, when enabled, instead require a
usable writer identity such as `process.pid` plus host or container attributes; see
[Configuration](configuration.md#11-fpm-request-metrics).

## Metric temporality

Temporality is chosen per instrument rather than by one scalar setting for all of them. Under a
`delta` preference:

| Instrument | Temporality |
|---|---|
| Counter | delta |
| Histogram | delta |
| ObservableCounter | delta |
| UpDownCounter | cumulative |
| ObservableUpDownCounter | cumulative |
| Gauge-like state | cumulative / last value |

The reason is the consumer's. For a counter, the change is the information. For worker memory or
work in flight, the current value is the information, and a consumer should not have to keep an
unbroken delta baseline since process start just to learn how much memory a worker is using now.

## Layers

The package wraps OpenTelemetry's **behaviour**, not its types. That rule decides where every
class lives, and `mago guard` enforces it instead of review:

```text
Api\                   the only public surface. No OpenTelemetry Context and no SDK.
                       The metrics API and SemConv are used directly — an instrument and
                       an attribute name carry no hidden execution state.
        │
Instrumentation\       one directory per Symfony component. Describes operations through
                       Api\, and execution boundaries through Internal\Operation's wider SPI.
        │
Internal\              the runtime, expressed as ports: SpanOpenerInterface, SpanOwner,
                       Propagation, IncomingTrace, TraceCorrelation, ActiveTraceIdentity,
                       DurationRecorder, BoundaryFlush. Not one OpenTelemetry Trace or
                       Context type.
        │
OpenTelemetry\Adapter\ the only place Context, SpanContext, Scope and TextMapPropagator
                       exist. Speaks the OpenTelemetry API; never the SDK.
        │
OpenTelemetry\Sdk\     providers, exporters, transports, resource, flush and finalization.
                       The one place `OpenTelemetry\SDK` may be named.
        │
DependencyInjection\   the composition root, which sees all of it.
```

The direction that is easy to get wrong is the last one inward. A class under `Internal`
reaching into `OpenTelemetry\Sdk` has inverted the dependency exactly as much as one importing
the SDK, and it is the easier mistake because the name looks local. It happened twice; the guard
now forbids it.

The rule holds without exception, and the guard baseline is empty. The last one to go was
the span itself: `SpanOpenerInterface::open()` used to return the concrete owner, so a port
in `Internal` named `ScopeInterface` through its return type. It returns `SpanOwner` now —
five things an operation needs from a span it owns, none of them an OpenTelemetry concept —
and the span, its activation and its context reach exactly as far as the adapter.

The operation that records no span at all does not reach even that far: `InertSpan` is owner
and view in one object with no OpenTelemetry object behind it, so an application that
switched the bundle off pays for no SDK type it will never use. It is not inert about two
things, because a suppressed operation still produces a duration metric: the trace that
duration belongs to, and the `error.type` it is labelled with.

`BoundaryFlush` is what the rule costs and what it buys. Everything that ends a unit of work —
HTTP terminate, a finished console command, a Messenger worker between messages, PHP shutdown —
says the same two things, and none of them has any business knowing that saying them means
calling `forceFlush()` and `shutdown()` on SDK providers. There is still exactly one
implementation and one owner of delivery; the interface exists so that owner can sit on the SDK
side of the perimeter while its callers stay on the Symfony side.

The same rule explains the public API. `Api\` contains no OpenTelemetry tracing or context type,
so an application can describe its own work without importing a tracing model — while the bundle's
own instrumentation uses `Internal\Operation`'s wider SPI, which can name an incoming trace, add
links or require an existing trace. One runtime, two surfaces; there is no second telemetry
system underneath.

## OpenTelemetry remains the SDK

Weaver does not reimplement the OpenTelemetry model. Sampling, Context, propagation, aggregation
and exporter contracts stay with the official SDK. What the bundle contributes is Symfony
lifecycle, framework instrumentation, worker isolation and a bounded export boundary — the parts
the SDK cannot know about because they are properties of the application's runtime, not of
OpenTelemetry.
