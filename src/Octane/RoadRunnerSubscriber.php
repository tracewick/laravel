<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Octane;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\OperationReleased;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\RoadRunner\RoadRunnerClient;
use Tracewick\Bridge\Laravel\HttpProfiler;

/** Attaches per-request HTTP profiling to a sequential Octane worker. */
final class RoadRunnerSubscriber
{
    private ?HttpProfiler $profiler = null;

    /** @param \Closure(Application): HttpProfiler $create */
    public function __construct(private readonly \Closure $create) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(WorkerStarting::class, $this->starting(...));
        $events->listen(RequestReceived::class, $this->received(...));
        $events->listen(RequestTerminated::class, $this->terminated(...));
        $events->listen(OperationReleased::class, $this->release(...));
        $events->listen(WorkerStopping::class, $this->stopping(...));
    }

    public function starting(WorkerStarting $event): void
    {
        if ($event->app->bound(Client::class) && $event->app->make(Client::class) instanceof RoadRunnerClient) {
            $this->profiler ??= ($this->create)($event->app);
        }
    }

    public function received(RequestReceived $event): void
    {
        $this->profiler?->begin($event->request);
    }

    public function terminated(RequestTerminated $event): void
    {
        $this->profiler?->finish($event->request);
    }

    public function release(): void
    {
        $this->profiler?->finishActiveRequest();
    }

    public function stopping(): void
    {
        $this->release();
        $this->profiler = null;
    }
}
