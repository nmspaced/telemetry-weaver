<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Instrumentation\Runtime;

use Nmspaced\TelemetryWeaver\Api\Metrics;
use Nmspaced\TelemetryWeaver\Internal\Clock\SystemClock;
use Nmspaced\TelemetryWeaver\Internal\Runtime\SymfonyRuntimeProfile;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * What the PHP runtime serving this worker is doing, sampled at each export.
 *
 * This is the one part of the package that measures the runtime rather than the
 * application, and it exists because the package's first principle — a worker process
 * survives thousands of requests without growing — is otherwise unfalsifiable from the
 * outside. A collector scraping the host sees one FrankenPHP or RoadRunner process and
 * cannot tell a worker whose heap is flat from one that climbs a megabyte an hour until
 * it is recycled; only the worker can answer that, and only about itself.
 *
 * The names are `php.*`, not `process.*`, because neither number is what the semantic
 * conventions mean by the process one. `process.memory.usage` is physical memory as the
 * operating system reports it and `process.uptime` is time since the process started;
 * what is measured here is the Zend allocator's heap and time since the first piece of
 * work was served. The conventions give a runtime-specific measurement its own top-level
 * namespace (`jvm.*`, `cpython.*`), and borrowing the process names would put two
 * different quantities under one series the moment a host-metrics collector reports the
 * real ones. Under FrankenPHP the difference is not a rounding error: its workers are
 * threads, each with its own allocator heap, inside one process.
 *
 * `memory_get_usage(true)` rather than `false`: the argument is whether to report what
 * PHP asked the allocator for or what userland is currently holding, and the first is
 * what actually grows a container towards its memory limit. It is an up-down counter
 * because the conventions make memory usage one — an amount, summed across workers into
 * something meaningful — where uptime is a gauge, a sample with no meaningful sum.
 *
 * Nothing here says which worker a sample came from, and nothing needs to. Telling
 * workers apart is the resource's job, through `service.instance.id` (see
 * `ResourceInfoFactory`), and it has to be: a label on these two instruments would leave
 * every other cumulative metric the worker exports colliding with its siblings'.
 *
 * The callbacks run at collection time, on whatever execution happens to be crossing a
 * flush boundary. They read two process-global numbers and touch nothing else — no
 * request, no container, no state that could belong to the execution that happens to be
 * paying for the flush.
 *
 * Registration is idempotent and the handles are kept: an observable callback reports
 * for exactly as long as its handle is held, and registering twice would report every
 * measurement twice.
 *
 * Nothing registers this at boot. It is attached to the events that mean "this process
 * serves work repeatedly" — an incoming request, a worker loop — so a one-shot console
 * command never starts reporting. A `cache:clear` that lives two seconds would otherwise
 * contribute a sample of its own ephemeral heap and an uptime of two seconds, which is
 * noise in the one series that is supposed to show a slow climb. A Messenger worker is
 * a console command and does report, because it is the process this metric exists for.
 *
 * A request pipeline — FPM, or a worker that clones its kernel after each request — never
 * registers, whatever the metrics switches say. Its providers live for one request, so the
 * uptime would be measured from the start of that request and the heap sample would be one
 * point per request under a resource that has no worker to attribute it to.
 */
final class ProcessMetrics
{
    public const string MEMORY_USAGE = 'php.memory.usage';

    public const string UPTIME = 'php.worker.uptime';

    /** @var list<ObservableCallbackInterface> */
    private array $callbacks = [];

    private readonly int $startedAt;

    public function __construct(
        private readonly Metrics $metrics,
        private readonly SymfonyRuntimeProfile $runtime,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->startedAt = $clock->now();
    }

    /**
     * Idempotent, and called from more than one place on purpose: whichever kind of work
     * this process turns out to serve, the first piece of it starts the reporting.
     */
    public function register(): void
    {
        if ($this->callbacks !== [] || !$this->runtime->recordsWorkerMetrics()) {
            return;
        }

        $this->callbacks[] = $this->metrics->observableUpDownCounter(
            self::MEMORY_USAGE,
            static function (ObserverInterface $observer): void {
                $observer->observe(\memory_get_usage(true));
            },
            'By',
            'Memory the Zend allocator currently holds for this worker.',
        );

        $this->callbacks[] = $this->metrics->observableGauge(
            self::UPTIME,
            function (ObserverInterface $observer): void {
                $observer->observe($this->uptime());
            },
            's',
            'Time since this worker served its first piece of work.',
        );
    }

    /**
     * Monotonic, so a clock adjustment cannot make a worker look younger than it is.
     * Measured from construction, which is the first piece of work this process served —
     * more honest than a boot time in a runtime that may have booted long before it was
     * given anything to do, and the reason this is not `process.uptime`.
     */
    private function uptime(): float
    {
        return \max(0, $this->clock->now() - $this->startedAt) / ClockInterface::NANOS_PER_SECOND;
    }
}
