<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Tracewick\Bridge\Laravel\HttpProfiler;
use Tracewick\Bridge\Laravel\ProfileRequest;
use Tracewick\Bridge\Laravel\TracewickServiceProvider;
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

final class HttpLifecycleTest extends TestCase
{
    /** @return list<class-string<TracewickServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [TracewickServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/items/{id}', static fn(): string => 'ok')->name('items.show');
        $router->addRoute('PROPFIND', '/webdav', static fn(): string => 'ok');
    }

    public function testNamedRequestDeliversExactlyOnceOnTermination(): void
    {
        $profiles = $this->recordProfiles();

        self::assertInstanceOf(Application::class, $this->app);
        $kernel = $this->app->make(Kernel::class);
        $request = Request::create('/items/42?token=private');
        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $profiles);

        $other = Request::create('/other');
        $this->app->make(ProfileRequest::class)->terminate($other);
        self::assertCount(0, $profiles);

        $kernel->terminate($request, $response);
        $kernel->terminate($request, $response);
        self::assertCount(1, $profiles);
        $profile = $profiles[0];
        self::assertInstanceOf(Profile::class, $profile);
        self::assertEquals(['route' => 'items.show', 'method' => 'GET'], $profile->labels->toArray());
    }

    public function testSequentialRequestsKeepBoundedRouteAndMethodLabels(): void
    {
        $profiles = $this->recordProfiles();

        $this->get('/items/42')->assertOk();
        $this->call('PROPFIND', '/webdav')->assertOk();
        $this->get('/missing/73?token=private')->assertNotFound();

        self::assertCount(3, $profiles);
        $expected = [
            ['route' => 'items.show', 'method' => 'GET'],
            ['route' => 'unnamed', 'method' => 'OTHER'],
            ['route' => 'unmatched', 'method' => 'GET'],
        ];

        foreach ($profiles as $index => $profile) {
            self::assertEquals($expected[$index], $profile->labels->toArray());
        }
    }

    public function testInvalidRequestMethodMetadataDoesNotBreakTheMiddleware(): void
    {
        $profiles = $this->recordProfiles();
        self::assertInstanceOf(Application::class, $this->app);
        $middleware = $this->app->make(ProfileRequest::class);
        $request = Request::create('/items/42');
        $request->server->set('REQUEST_METHOD', ['invalid']);
        $response = new Response('application response');

        self::assertSame($response, $middleware->handle($request, static fn(): Response => $response));
        self::assertCount(0, $profiles);
        $middleware->terminate($request);

        self::assertCount(1, $profiles);
        $profile = $profiles[0];
        self::assertInstanceOf(Profile::class, $profile);
        self::assertEquals(['route' => 'unmatched', 'method' => 'OTHER'], $profile->labels->toArray());
    }

    public function testNestedMiddlewareCannotFinishTheOuterProfile(): void
    {
        $profiles = $this->recordProfiles();
        self::assertInstanceOf(Application::class, $this->app);
        $middleware = $this->app->make(ProfileRequest::class);
        $request = Request::create('/items/42');
        $failure = new \RuntimeException('Inner middleware failed.');
        $caught = null;

        $response = $middleware->handle($request, static function (Request $request) use ($middleware, $failure, &$caught): Response {
            try {
                return $middleware->handle($request, static function () use ($failure): never {
                    throw $failure;
                });
            } catch (\RuntimeException $error) {
                $caught = $error;

                return new Response('recovered');
            }
        });

        self::assertSame($failure, $caught);
        self::assertSame('recovered', $response->getContent());
        self::assertCount(0, $profiles);

        $middleware->terminate($request);

        self::assertCount(1, $profiles);
    }

    public function testFailedDeliveryDoesNotChangeTheResponse(): void
    {
        $sink = $this->createStub(Sink::class);
        $sink->method('send')->willReturn(Delivery::failed('Test sink unavailable.'));
        $this->bindProfiler($sink);

        $this->get('/items/42')->assertOk()->assertSee('ok');

        self::assertInstanceOf(Application::class, $this->app);
        $diagnostics = $this->app->make(HttpProfiler::class)->diagnostics();
        self::assertNotNull($diagnostics);
        self::assertSame(1, $diagnostics->failedProfiles);
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
        $this->bindProfiler($sink);

        return $profiles;
    }

    private function bindProfiler(Sink $sink): void
    {
        $sampler = $this->createStub(Sampler::class);
        $sampler->method('isRunning')->willReturn(true);
        $sampler->method('flush')->willReturn(new SampleBatch(
            EventType::Wall,
            100,
            1000,
            2000,
            [new Sample([new Frame('test_route')], 1, 1500)],
        ));

        self::assertInstanceOf(Application::class, $this->app);
        $this->app->instance(HttpProfiler::class, new HttpProfiler(
            new UnitOfWorkProfiler($sampler, $sink, new ProfilerConfig(sampleRate: 1.0)),
        ));
    }
}
