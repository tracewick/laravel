<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Octane;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Octane\Events\OperationReleased;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Swoole\Http\Server;
use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;
use Tracewick\Runtime\ContinuousProfiler;

/** Attaches continuous profiling to a Swoole or OpenSwoole HTTP worker. */
final class SwooleWorkerSubscriber
{
    private ?ContinuousProfiler $profiler = null;
    private ?int $lastPulseSecond = null;

    /** @param \Closure(Application): ?ContinuousProfiler $create */
    public function __construct(private readonly \Closure $create, private readonly Clock $clock = new SystemClock()) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WorkerStarting::class, $this->starting(...));
        $events->listen(OperationReleased::class, $this->release(...));
        $events->listen(WorkerStopping::class, $this->stopping(...));
    }

    public function starting(WorkerStarting $event): void
    {
        if ($this->profiler !== null || !$event->app->bound(Server::class)) {
            return;
        }

        $server = $event->app->make(Server::class);
        if (($server->taskworker ?? null) !== false) {
            return;
        }

        $profiler = ($this->create)($event->app);
        $profiler?->start();
        if ($profiler?->isRunning() === true) {
            $this->profiler = $profiler;
            $profiler->registerShutdown();
        }
    }

    public function release(): void
    {
        if ($this->profiler === null) {
            return;
        }

        // Amortize spool work over requests. A periodic timer can keep a reloading worker alive.
        $second = intdiv($this->clock->nowNs(), 1_000_000_000);
        if ($second !== $this->lastPulseSecond) {
            $this->lastPulseSecond = $second;
            $this->profiler->pulse();
        }
    }

    public function stopping(): void
    {
        $this->profiler?->stop();
        $this->profiler = null;
        $this->lastPulseSecond = null;
    }
}
