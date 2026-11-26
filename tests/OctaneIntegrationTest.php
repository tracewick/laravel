<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Events\OperationReleased;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\RequestContext;
use Laravel\Octane\RoadRunner\RoadRunnerClient;
use Laravel\Octane\Worker;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tracewick\Bridge\Laravel\HttpProfiler;
use Tracewick\Bridge\Laravel\Octane\RoadRunnerSubscriber;
use Tracewick\Bridge\Laravel\ProfileRequest;
use Tracewick\Bridge\Laravel\ProfilerFactory;
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

final class OctaneIntegrationTest extends TestCase
{
    /** @return list<class-string<OctaneServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [OctaneServiceProvider::class];
    }

    #[DataProvider('modes')]
    public function testProviderOnlyAttachesTheOptedInOctaneLifecycle(?string $mode, bool $enabled, int $listeners): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $config = $this->app->make(Repository::class);
        if ($mode !== null) {
            $config->set('tracewick.octane_mode', $mode);
        }
        $config->set('tracewick.enabled', $enabled);
        $events = $this->app->make(Dispatcher::class);
        self::assertInstanceOf(\Illuminate\Events\Dispatcher::class, $events);
        $starting = count($events->getListeners(WorkerStarting::class));
        $received = count($events->getListeners(RequestReceived::class));
        $released = count($events->getListeners(OperationReleased::class));
        $previous = $_ENV['LARAVEL_OCTANE'] ?? null;
        $_ENV['LARAVEL_OCTANE'] = '1';

        try {
            $this->app->register(TracewickServiceProvider::class);
            self::assertSame($mode ?? 'off', $config->get('tracewick.octane_mode'));
            $kernel = $this->app->make(Kernel::class);
            self::assertInstanceOf(LaravelKernel::class, $kernel);
            self::assertFalse($kernel->hasMiddleware(ProfileRequest::class));
            self::assertNull($this->app->make(HttpProfiler::class)->diagnostics());
            self::assertCount($starting + $listeners, $events->getListeners(WorkerStarting::class));
            self::assertCount($received + (int) ($enabled && $mode === 'roadrunner'), $events->getListeners(RequestReceived::class));
            self::assertCount($released + $listeners, $events->getListeners(OperationReleased::class));

            if ($mode === 'roadrunner' && $enabled) {
                $this->app->instance(Client::class, $this->createStub(RoadRunnerClient::class));
                self::assertNotNull((new ProfilerFactory())->forHttpWorker($this->app)->diagnostics());
            }
            if ($mode === 'swoole') {
                $this->app->instance(\Swoole\Http\Server::class, (object) ['taskworker' => false]);
                $this->app->instance(LoggerInterface::class, $this->createStub(LoggerInterface::class));
            }
            $events->dispatch(new WorkerStarting($this->app));
            $events->dispatch(new WorkerStopping($this->app));
        } finally {
            if ($previous === null) {
                unset($_ENV['LARAVEL_OCTANE']);
            } else {
                $_ENV['LARAVEL_OCTANE'] = $previous;
            }
        }
    }

    /** @return iterable<string, array{?string, bool, int}> */
    public static function modes(): iterable
    {
        yield 'default' => [null, true, 0];
        yield 'unknown mode' => ['unknown', true, 0];
        yield 'disabled' => ['roadrunner', false, 0];
        yield 'RoadRunner' => ['roadrunner', true, 1];
        yield 'Swoole' => ['swoole', true, 1];
    }

    public function testRoadRunnerReusesItsSamplerAcrossSandboxesAndFinishesAnEscapingError(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $profiles = [];
        $sampler = $this->createMock(Sampler::class);
        $sampler->expects(self::exactly(4))->method('start');
        $sampler->expects(self::exactly(4))->method('stop');
        $sampler->method('isRunning')->willReturn(true);
        $sampler->method('flush')->willReturn(new SampleBatch(
            EventType::Wall,
            100,
            1000,
            2000,
            [new Sample([new Frame('test_route')], 1, 1500)],
        ));
        $sink = $this->createStub(Sink::class);
        $sink->method('send')->willReturnCallback(static function (Profile $profile) use (&$profiles): Delivery {
            $profiles[] = $profile->labels->toArray();

            return Delivery::delivered();
        });
        $creations = 0;
        $subscriber = new RoadRunnerSubscriber(static function (ApplicationContract $app) use (&$creations, $sampler, $sink): HttpProfiler {
            ++$creations;

            return new HttpProfiler(new WorkProfiler(new UnitOfWorkProfiler($sampler, $sink, new ProfilerConfig(sampleRate: 1.0))));
        });

        $app = $this->app;
        $events = $app->make(Dispatcher::class);
        $events->subscribe($subscriber);
        $unused = Request::create('/before-worker-start');
        $sandbox = clone $app;
        $events->dispatch(new RequestReceived($app, $sandbox, $unused));
        $events->dispatch(new RequestTerminated($app, $sandbox, $unused, new Response()));
        $events->dispatch(new OperationReleased($app));

        $events->listen(RequestReceived::class, static function (RequestReceived $event) use ($app, $subscriber): void {
            self::assertSame($app, $event->app);
            self::assertNotSame($app, $event->sandbox);
            $other = Request::create('/foreign', 'PATCH');
            $subscriber->received(new RequestReceived($app, $event->sandbox, $other));
            $subscriber->terminated(new RequestTerminated($app, $event->sandbox, $other, new Response()));
        });
        $errors = [];
        $events->forget(WorkerErrorOccurred::class);
        $events->listen(WorkerErrorOccurred::class, static function (WorkerErrorOccurred $event) use (&$errors): void {
            $errors[] = $event->exception->getMessage();
        });
        $router = $app->make(Router::class);
        $router->get('/one', static fn(): string => 'one')->name('one');
        $router->post('/error', static fn(): string => 'error')->name('error');
        $router->get('/two', static fn(): string => 'two')->name('two');

        $client = $this->createMock(RoadRunnerClient::class);
        $client->expects(self::exactly(3))->method('respond');
        $client->expects(self::never())->method('error');
        $factory = $this->createStub(ApplicationFactory::class);
        $factory->method('createApplication')->willReturn($app);
        $worker = new Worker($factory, $client);
        $worker->onRequestHandled(static function (Request $request): void {
            if ($request->is('error')) {
                throw new \RuntimeException('A request-handled callback failed.');
            }
        });

        $events->dispatch(new WorkerStarting($app));
        $app->instance(Client::class, $this->createStub(Client::class));
        $events->dispatch(new WorkerStarting($app));
        self::assertSame(0, $creations);
        $app->instance(Client::class, $client);
        $worker->boot();
        $events->dispatch(new WorkerStarting($app));
        foreach (['/one' => 'GET', '/error' => 'POST', '/two' => 'GET'] as $path => $method) {
            $before = count($profiles);
            $worker->handle(Request::create($path, $method), new RequestContext());
            self::assertCount($before + ($path === '/error' ? 0 : 1), $profiles);
            $worker->operationReleased();
            self::assertCount($before + 1, $profiles);
        }
        $events->dispatch(new RequestReceived($app, $sandbox, Request::create('/pending-at-stop')));
        self::assertCount(3, $profiles);
        $worker->terminate();
        self::assertCount(4, $profiles);
        self::assertSame(1, $creations);
        $worker->boot();
        $worker->terminate();
        self::assertSame(2, $creations);
        self::assertSame(['A request-handled callback failed.'], $errors);
        self::assertSame([
            ['method' => 'GET', 'route' => 'one'],
            ['method' => 'POST', 'route' => 'error'],
            ['method' => 'GET', 'route' => 'two'],
            ['method' => 'GET', 'route' => 'unmatched'],
        ], $profiles);
    }
}
