<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tracewick\Bridge\Laravel\HttpProfiler;
use Tracewick\Bridge\Laravel\ProfileJob;
use Tracewick\Bridge\Laravel\TracewickServiceProvider;
use Tracewick\Bridge\Laravel\WorkProfiler;
use Tracewick\Profile\Profile;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\Sampler;
use Tracewick\Sink\Sink;
use Tracewick\Transport\Delivery;

final class QueueIntegrationTest extends TestCase
{
    /** @return list<class-string<TracewickServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [TracewickServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        self::assertInstanceOf(Application::class, $app);
        $app->make(Repository::class)->set([
            'tracewick.profile_queue' => true,
            'queue.connections.inline' => ['driver' => 'sync'],
            'queue.connections.tracewick-tests' => [
                'driver' => 'database',
                'connection' => 'tracewick-tests',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'database.connections.tracewick-tests' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
    }

    public function testSequentialAttemptsUseClassLabelsAndSkipNestedSyncJobs(): void
    {
        $profiles = $this->recordProfiles();
        $queue = $this->databaseQueue();
        $this->enqueue(new QueueProbeJob('private-payload'));
        $queue->push(strtolower(InlineProbeJob::class) . '@fire', []);

        $this->runAttempt($queue);
        $this->runAttempt($queue);

        self::assertSame(0, $queue->size());
        self::assertSame([QueueProbeJob::class, InlineProbeJob::class], $this->jobLabels($profiles));
        self::assertInstanceOf(Application::class, $this->app);
        self::assertSame(2, $this->app->make(WorkProfiler::class)->diagnostics()?->deliveredProfiles);
    }

    public function testReleaseRetryAndFinalFailureEachFinishAnAttempt(): void
    {
        $profiles = $this->recordProfiles();
        $queue = $this->databaseQueue();
        $this->enqueue(new QueueProbeJob('private-payload', releaseFirst: true));
        $this->enqueue(new QueueProbeJob('private-payload', fail: true));
        self::assertInstanceOf(Application::class, $this->app);
        /** @var \ArrayObject<int, JobFailed> $failed */
        $failed = new \ArrayObject();
        $this->app->make(Dispatcher::class)->listen(JobFailed::class, static function (JobFailed $event) use ($failed): void {
            $failed[] = $event;
        });

        $this->runAttempt($queue);
        self::assertCount(1, $profiles);
        self::assertSame(2, $queue->size());

        $options = new WorkerOptions(maxTries: 2);
        $exceptions = [];
        for ($attempt = 2; $attempt <= 4; ++$attempt) {
            try {
                $this->runAttempt($queue, $options);
            } catch (\RuntimeException $error) {
                $exceptions[] = $error->getMessage();
            }
            self::assertCount($attempt, $profiles);
        }

        self::assertSame(['private-payload', 'private-payload'], $exceptions);
        self::assertCount(1, $failed);
        self::assertSame(0, $queue->size());
        self::assertSame(array_fill(0, 4, QueueProbeJob::class), $this->jobLabels($profiles));
    }

    public function testSyncConnectionDoesNotRotateAnActiveHttpOrConsoleProfile(): void
    {
        $profiles = $this->recordProfiles();
        self::assertInstanceOf(Application::class, $this->app);
        $sync = $this->app->make(Factory::class)->connection('inline');
        $http = $this->app->make(HttpProfiler::class);
        $request = Request::create('/items');
        self::assertTrue($http->begin($request));

        $sync->push(new InlineProbeJob());
        self::assertCount(0, $profiles);
        $http->finish($request);

        $work = $this->app->make(WorkProfiler::class);
        $command = new \stdClass();
        self::assertTrue($work->begin($command, ['command' => 'app:report']));
        $sync->push(new InlineProbeJob());
        self::assertCount(1, $profiles);
        $work->finish($command);

        $labels = [];
        foreach ($profiles as $profile) {
            $labels[] = $profile->labels->toArray();
        }
        self::assertEquals([['route' => 'unmatched', 'method' => 'GET'], ['command' => 'app:report']], $labels);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('unknownClassPayloads')]
    public function testPayloadMetadataProducesTheFixedFallback(array $payload): void
    {
        $profiles = $this->recordProfiles();
        $job = $this->createStub(Job::class);
        $job->method('payload')->willReturn($payload);

        $this->dispatchAttempt($job);

        self::assertSame(['unknown'], $this->jobLabels($profiles));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unknownClassPayloads(): iterable
    {
        yield 'display name only' => [['displayName' => 'private-user-data']];
        yield 'invalid object metadata' => [['job' => CallQueuedHandler::class . '@call', 'data' => 'private']];
        yield 'unloaded class' => [['job' => CallQueuedHandler::class . '@call', 'data' => ['commandName' => 'Example\\UnloadedJob']]];
        yield 'anonymous class' => [['job' => (new class {})::class . '@fire']];
    }

    public function testMetadataErrorAndUnmatchedCompletionDoNotInterfere(): void
    {
        $profiles = $this->recordProfiles();
        $job = $this->createStub(Job::class);
        $job->method('payload')->willThrowException(new \RuntimeException('Private driver error.'));
        self::assertInstanceOf(Application::class, $this->app);
        $listener = $this->app->make(ProfileJob::class);

        $listener->starting(new JobProcessing('tracewick-tests', $job));
        $listener->finished(new JobAttempted('tracewick-tests', $this->createStub(Job::class)));
        self::assertCount(0, $profiles);
        $listener->finished(new JobAttempted('tracewick-tests', $job));
        $listener->finished(new JobAttempted('tracewick-tests', $job));

        self::assertSame(['unknown'], $this->jobLabels($profiles));
    }

    public function testWorkerContinuesWhenProfilerResolutionFails(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->singleton(WorkProfiler::class, static function (): never {
            throw new \RuntimeException('Profiler unavailable.');
        });
        $queue = $this->databaseQueue();
        $this->enqueue(new QueueProbeJob('private-payload'));

        $this->runAttempt($queue);

        self::assertSame(0, $queue->size());
    }

    private function databaseQueue(): DatabaseQueue
    {
        self::assertInstanceOf(Application::class, $this->app);
        $database = $this->app->make(DatabaseManager::class)->connection('tracewick-tests');
        $database->getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $queue = $this->app->make(Factory::class)->connection('tracewick-tests');
        self::assertInstanceOf(DatabaseQueue::class, $queue);

        return $queue;
    }

    private function enqueue(QueueProbeJob $job): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->make(QueueingDispatcher::class)->dispatchToQueue($job->onConnection('tracewick-tests'));
    }

    private function runAttempt(DatabaseQueue $queue, ?WorkerOptions $options = null): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $job = $queue->pop();
        self::assertInstanceOf(Job::class, $job);
        $worker = $this->app->make('queue.worker');
        self::assertInstanceOf(Worker::class, $worker);
        $worker->process('tracewick-tests', $job, $options ?? new WorkerOptions());
    }

    private function dispatchAttempt(Job $job): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $events = $this->app->make(Dispatcher::class);
        $events->dispatch(new JobProcessing('tracewick-tests', $job));
        $events->dispatch(new JobAttempted('tracewick-tests', $job));
    }

    /** @return \ArrayObject<int, Profile> */
    private function recordProfiles(): \ArrayObject
    {
        /** @var \ArrayObject<int, Profile> $profiles */
        $profiles = new \ArrayObject();
        $sink = $this->createStub(Sink::class);
        $sink->method('send')->willReturnCallback(static function (Profile $profile) use ($profiles): Delivery {
            $profiles[] = $profile;

            return Delivery::delivered();
        });
        $sampler = $this->createStub(Sampler::class);
        $sampler->method('isRunning')->willReturn(true);
        $sampler->method('flush')->willReturn(new SampleBatch(
            EventType::Wall,
            100,
            1000,
            2000,
            [new Sample([new Frame('test_job')], 1, 1500)],
        ));

        self::assertInstanceOf(Application::class, $this->app);
        $this->app->instance(WorkProfiler::class, new WorkProfiler(
            new UnitOfWorkProfiler($sampler, $sink, new ProfilerConfig(sampleRate: 1.0)),
        ));

        return $profiles;
    }

    /**
     * @param \ArrayObject<int, Profile> $profiles
     *
     * @return list<string>
     */
    private function jobLabels(\ArrayObject $profiles): array
    {
        $names = [];
        foreach ($profiles as $profile) {
            $labels = $profile->labels->toArray();
            self::assertCount(1, $labels);
            self::assertArrayHasKey('job', $labels);
            $names[] = $labels['job'];
        }

        return $names;
    }
}

final class QueueProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        private readonly string $privateValue,
        private readonly bool $releaseFirst = false,
        private readonly bool $fail = false,
    ) {}

    public function displayName(): string
    {
        return $this->privateValue;
    }

    public function handle(Factory $queues): void
    {
        if ($this->releaseFirst && $this->attempts() === 1) {
            $this->release();

            return;
        }

        $queues->connection('inline')->push(new InlineProbeJob());
        if ($this->fail) {
            throw new \RuntimeException($this->privateValue);
        }
    }
}

final class InlineProbeJob
{
    public function handle(): void {}

    /** @param array<mixed> $data */
    public function fire(Job $job, array $data): void
    {
        $job->delete();
    }
}
