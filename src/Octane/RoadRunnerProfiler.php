<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Octane;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\RoadRunner\RoadRunnerClient;
use Tracewick\Bridge\Laravel\HttpProfiler;

/** One HTTP profiler shared by the sequential worker and its request sandboxes. */
final class RoadRunnerProfiler
{
    private ?HttpProfiler $profiler = null;
    private ?Request $active = null;

    /** @param \Closure(Application): HttpProfiler $create */
    public function __construct(private readonly \Closure $create) {}

    public function starting(WorkerStarting $event): void
    {
        if ($event->app->bound(Client::class) && $event->app->make(Client::class) instanceof RoadRunnerClient) {
            $this->profiler ??= ($this->create)($event->app);
        }
    }

    public function received(RequestReceived $event): void
    {
        if ($this->active === null && $this->profiler?->begin($event->request) === true) {
            $this->active = $event->request;
        }
    }

    public function terminated(RequestTerminated $event): void
    {
        if ($this->active === $event->request) {
            $this->release();
        }
    }

    public function release(): void
    {
        if ($this->active !== null) {
            $this->profiler?->finish($this->active);
            $this->active = null;
        }
    }

    public function stopping(): void
    {
        $this->release();
        $this->profiler = null;
    }
}
