# Architecture and lifecycle

Telemetry Weaver is a lifecycle layer between Symfony and the OpenTelemetry PHP SDK. In
long-running PHP the hard part is not starting a span. It is deciding **who owns a piece of state
and when it must stop existing**.

## Four lifetimes

The bundle keeps four things apart that PHP's request model traditionally lets collapse into one:

1. the PHP process, or worker;
2. the Symfony kernel and service container;
3. one request, message or command;
4. one telemetry operation.

In PHP-FPM, the first two end with the third, which is why request-scoped telemetry can be left
to process shutdown and usually is. In a shared worker they do not end together: the process and
the container serve hundreds of requests. Anything scoped to one of them and cleaned up "at
shutdown" is in practice never cleaned up. It is inherited by the next request instead.

So a request, a message and a command are **execution boundaries**, and every piece of state the
bundle created for that unit of work is released there.

## Runtime profile

Which boundary behaviour applies follows Symfony's `kernel.runtime_mode.web` and
`kernel.runtime_mode.worker`, resolved from `APP_RUNTIME_MODE` and never from `PHP_SAPI`.

| Runtime shape | Mode | Effective model | Finalization |
|---|---|---|---|
| PHP-FPM, one request per PHP execution | `web=1&worker=0` | the pipeline belongs to one request | `shutdown()` on terminate |
| Shared HTTP worker | `worker=1` | the pipeline outlives requests | `forceFlush()` per request, `shutdown()` at process exit |
| Worker that rebuilds its kernel per request | `worker=2` | the process survives, the container does not | provider shutdown per request, worker identity stays stable |
| Console, Messenger | — | events define the boundaries | flush per command or message, shutdown at process exit |

FPM does not give you a new OS process per request: a pool child serves many requests in turn.
What ends with the request is the PHP execution and the container built for it. That is why the
pipeline can be finalized on terminate, and why the cooldown a failing collector caused is not
remembered by the next request.

`forceFlush()` drains a pipeline that keeps living. `shutdown()` is terminal. Conflating the two
is how a worker ends up with a provider that was shut down on its first request.

If a custom RoadRunner integration does not expose the runtime mode automatically, set
`APP_RUNTIME_MODE=web=1&worker=1` so a shared HTTP worker is visible as one.

## Ownership: created here, or merely found here

Instrumentation frequently finds an active span, and finding one is not owning it.

The bundle tracks the objects it created — spans, context activations, measurements — and closes
or detaches only those. Two classes of worker bug follow directly from getting this wrong, and
both have been seen in the wild:

- ending a span that belongs to another layer, which truncates a trace someone else is still
  building;
- leaving a request's context activation attached, so the next request on that worker starts
  inside the previous request's trace.

An explicit `Operation` owns its span, its activation and its measurement until `finish()` or
`abandon()`. `run()` guarantees that one of the two happens, including on the exception path,
which is why it is the form to prefer.

At a boundary, work that is still unfinished is **abandoned**: its state is released without
pretending the operation completed. Abandonment is the correct outcome for a request that died
halfway, not a failure mode to avoid, and it is what keeps the next request clean.

An HTTP main request or subrequest confines the context activated inside it. When it detaches at
`kernel.finish_request`, every scope still stacked above its own is released innermost first,
whether an unfinished `operation()->start()` holds it or it was activated directly through
OpenTelemetry. The package's own operations among them are ended at terminate or reset, without
recording their durations; until then lazy work may still resume and finish. Scopes activated
before the request stay. Confinement also applies to excluded paths and with server tracing off,
and each Fiber releases only its own stack. Operations started outside a request still need an
explicit `finish()` or `abandon()`; prefer `run()` wherever possible.

A consumed Messenger message confines its handlers the same way. It has a single phase: when the
message has been handled, whatever is still activated inside it is released and abandoned at once.

The destructor and PHP-shutdown paths do the same thing conservatively, by detaching context.
They are not a promise that unfinished telemetry will be delivered after a fatal error or a
killed process.

## Fail-open

A telemetry failure must never become an application failure. An instrumentation that throws does
not replace a return value, and never replaces the original business exception.

Fail-open is a type here, not a `try`/`catch` sprinkled at call sites. Failures funnel through
the `Internal/Diagnostics` reporters, which rate-limit them, and the `Safe*` and `Resilient*`
wrappers make the fail-open path something the compiler can see.

## Providers

There is at most one provider per signal, held by a registry the container populates. Providers
are created lazily, and finalization only touches the ones that were actually instantiated:
finishing a request must not construct a MeterProvider purely to shut it down.

Provider factories deliberately do not register SDK shutdown callbacks. Doing so exported metrics
twice, once from the callback and once from the boundary. Delivery and finalization have exactly
one owner.

A provider replaced through DI is still adopted into the registry, so its boundary
`forceFlush()` and `shutdown()` still happen. Nothing *inside* it is wrapped or controlled; see
[SDK customization](sdk-customization.md).

## Resource identity

Long-lived producers receive a `service.instance.id` from the SDK's `ServiceInstance` detector. It
is stable for that PHP execution, and it is what distinguishes workers that otherwise publish
identical resource attributes.

A value you set wins over the generated one, so set it only when it really identifies one
concurrent producer. The same hostname on every worker process is worse than the default.

Request-per-process pipelines deliberately do not get a random instance id per request, because
that would create a new series for every request. When request metrics are enabled, an FPM child
instead gets an id *derived* from `process.pid` and its host or container attributes: stable
across the requests it serves, distinct between children, and the one resource attribute the
Prometheus mapping keys `instance` on. See
[Configuration](configuration.md#export-metrics-from-a-request-per-process-runtime-fpm-frankenphp_reset_kernel).

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
                       Propagation, IncomingTrace, TraceCorrelation, DurationRecorder,
                       BoundaryFlush. Not one OpenTelemetry Trace or Context type.
                       Reading the running trace is a port too, but a published one:
                       Api\ActiveTrace, because code outside the package needs it.
        │
OpenTelemetry\Adapter\ the only place Context, SpanContext, Scope and TextMapPropagator
                       exist. Speaks the OpenTelemetry API; never the SDK.
        │
OpenTelemetry\Sdk\     providers, exporters, transports, resource, flush and finalization.
                       The one place `OpenTelemetry\SDK` may be named.
        │
DependencyInjection\   the composition root, which sees all of it.
```

The direction that is easy to get wrong is the last one inward. A class under `Internal` reaching
into `OpenTelemetry\Sdk` has inverted the dependency exactly as much as one importing the SDK,
and it is the easier mistake because the name looks local. It happened twice, and the guard now
forbids it.

The rule holds without exception, and the guard baseline is empty. The last thing to go was the
span itself. `SpanOpenerInterface::open()` used to return the concrete owner, so a port in
`Internal` named `ScopeInterface` through its return type. It returns `SpanOwner` now — five
things an operation needs from a span it owns, none of them an OpenTelemetry concept — and the
span, its activation and its context reach exactly as far as the adapter.

The operation that records no span at all does not reach even that far. `InertSpan` is owner and
view in one object with no OpenTelemetry object behind it, so an application that switched the
bundle off pays for no SDK type it will never use. It stays inert about everything except two
things, because a suppressed operation still produces a duration metric: the trace that duration
belongs to, and the `error.type` it is labelled with.

`BoundaryFlush` is what the rule costs and what it buys. Everything that ends a unit of work —
HTTP terminate, a finished console command, a Messenger worker between messages, PHP shutdown —
says the same two things, and none of them has any business knowing that saying them means
calling `forceFlush()` and `shutdown()` on SDK providers. There is still exactly one
implementation and one owner of delivery. The interface exists so that owner can sit on the SDK
side of the perimeter while its callers stay on the Symfony side.

The same rule explains the public API. `Api\` contains no OpenTelemetry tracing or context type,
so an application can describe its own work without importing a tracing model, while the bundle's
own instrumentation uses `Internal\Operation`'s wider SPI, which can name an incoming trace, add
links or require an existing trace. One runtime, two surfaces, and no second telemetry system
underneath.

## OpenTelemetry remains the SDK

Weaver does not reimplement the OpenTelemetry model. Sampling, Context, propagation, aggregation
and exporter contracts stay with the official SDK. What the bundle contributes is Symfony
lifecycle, framework instrumentation, worker isolation and a bounded export boundary: the parts
the SDK cannot know about, because they are properties of the application's runtime rather than
of OpenTelemetry.
