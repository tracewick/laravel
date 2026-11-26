<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;

final class ProfileJob
{
    private ?WorkProfiler $profiler = null;

    public function __construct(private readonly Application $app) {}

    public function starting(JobProcessing $event): void
    {
        if ($event->job instanceof SyncJob) {
            return;
        }

        try {
            $profiler = $this->app->make(WorkProfiler::class);
            if ($profiler->begin($event->job, ['job' => 'unknown'])) {
                $this->profiler = $profiler;
            }
        } catch (\Throwable) {
            // Keep job execution independent of the profiler binding.
        }
    }

    public function finished(JobAttempted $event): void
    {
        $profiler = $this->profiler;
        if ($profiler === null || !$profiler->owns($event->job)) {
            return;
        }

        try {
            $profiler->label($event->job, 'job', $this->jobClass($event->job));
        } catch (\Throwable) {
            // Keep the fallback label when a driver cannot provide job metadata.
        } finally {
            $profiler->finish($event->job);
            $this->profiler = null;
        }
    }

    private function jobClass(Job $job): string
    {
        $payload = $job->payload();
        $handler = $payload['job'] ?? null;
        if (!is_string($handler)) {
            return 'unknown';
        }

        if ($handler === CallQueuedHandler::class . '@call') {
            $data = $payload['data'] ?? null;
            $name = is_array($data) ? ($data['commandName'] ?? null) : null;
        } else {
            $name = explode('@', $handler, 2)[0];
        }

        // Resolve metadata after the attempt, using only classes the worker has loaded.
        if (!is_string($name) || !class_exists($name, false)) {
            return 'unknown';
        }

        $class = new \ReflectionClass($name);

        return $class->isAnonymous() ? 'unknown' : $class->getName();
    }
}
