<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tracewick\Bridge\Laravel\HttpProfiler;
use Tracewick\Bridge\Laravel\ProfileCommand;
use Tracewick\Bridge\Laravel\ProfileJob;
use Tracewick\Bridge\Laravel\ProfileRequest;
use Tracewick\Bridge\Laravel\TracewickServiceProvider;
use Tracewick\Bridge\Laravel\WorkProfiler;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\Sampler;
use Tracewick\Sink\Sink;

final class HttpIntegrationTest extends TestCase
{
    /** @return list<class-string<TracewickServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [TracewickServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/named/{id}', static fn(): string => 'ok')->name('items.show');
        $router->get('/redirect', static fn(): Response => new Response('', 302, ['Location' => '/named/42']));
        $router->get('/error', static function (): never {
            throw new \RuntimeException('Render this with the application handler.');
        });
    }

    public function testProviderProfilesTheHttpKernelAndPreservesResponses(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->make(Repository::class)->set('tracewick.base_labels', ['environment' => 'test']);
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(\Illuminate\Foundation\Http\Kernel::class, $kernel);
        self::assertTrue($kernel->hasMiddleware(ProfileRequest::class));

        foreach (['/named/42?token=test' => 200, '/redirect' => 302, '/missing' => 404, '/error' => 500] as $path => $status) {
            $request = Request::create($path);
            $response = $kernel->handle($request);
            self::assertSame($status, $response->getStatusCode());
            $kernel->terminate($request, $response);
        }

        $profiler = $this->app->make(HttpProfiler::class);
        self::assertSame($profiler, $this->app->make(HttpProfiler::class));
        $diagnostics = $profiler->diagnostics();
        self::assertNotNull($diagnostics);

        if (!extension_loaded('excimer')) {
            self::assertSame('extension_missing', $diagnostics->inactiveSampler?->value);
        }
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidConfigurationIsFailOpen(string $setting, mixed $value): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->make(Repository::class)->set($setting, $value);

        $this->get('/named/42')->assertOk()->assertSee('ok');
        self::assertNull($this->app->make(HttpProfiler::class)->diagnostics());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidSettings(): iterable
    {
        yield 'sample rate' => ['tracewick.sample_rate', 'bogus'];
        yield 'application name' => ['tracewick.application_name', null];
        yield 'spool directory' => ['tracewick.spool_directory', null];
        yield 'labels container' => ['tracewick.base_labels', 'invalid'];
    }

    /** @param array<array-key, mixed> $labels */
    #[DataProvider('invalidBaseLabels')]
    public function testInvalidBaseLabelsWarnAndLeaveProfilingInactive(array $labels): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('Check the package configuration'));
        $this->app->instance(LoggerInterface::class, $logger);
        $this->app->make(Repository::class)->set('tracewick.base_labels', $labels);

        $this->get('/named/42')->assertOk()->assertSee('ok');
        self::assertNull($this->app->make(HttpProfiler::class)->diagnostics());
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidBaseLabels(): iterable
    {
        yield 'numeric key' => [[1 => 'test']];
        yield 'non-string value' => [['environment' => false]];
    }

    public function testThrowingWarningLoggerDoesNotBreakRequests(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->willThrowException(new \RuntimeException('Cannot log the warning.'));
        $this->app->instance(LoggerInterface::class, $logger);
        $this->app->make(Repository::class)->set('tracewick.sample_rate', 'bogus');

        $this->get('/named/42')->assertOk()->assertSee('ok');
        self::assertNull($this->app->make(HttpProfiler::class)->diagnostics());
    }

    public function testMiddlewareCanBeRegisteredManually(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->make(Repository::class)->set('tracewick.register_middleware', false);
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(\Illuminate\Foundation\Http\Kernel::class, $kernel);
        self::assertFalse($kernel->hasMiddleware(ProfileRequest::class));

        $kernel->prependMiddleware(ProfileRequest::class);
        $this->get('/named/42')->assertOk()->assertSee('ok');
        self::assertNotNull($this->app->make(HttpProfiler::class)->diagnostics());
    }

    public function testLoggerFailureLeavesRequestsWorking(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->bind(LoggerInterface::class, static function (): never {
            throw new \RuntimeException('Logger unavailable.');
        });

        $this->get('/named/42')->assertOk();
        self::assertNull($this->app->make(HttpProfiler::class)->diagnostics());
    }

    public function testMiddlewarePreservesAnEscapingException(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $middleware = $this->app->make(ProfileRequest::class);
        $profiler = $this->app->make(HttpProfiler::class);
        $request = Request::create('/first');
        $failure = new \RuntimeException('Application failure.');
        $request->setRouteResolver(static function (): never {
            throw new \RuntimeException('Route lookup failed.');
        });

        try {
            $middleware->handle($request, static function () use ($failure): never {
                throw $failure;
            });
            self::fail('The middleware must preserve application exceptions.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $next = Request::create('/second');
        self::assertTrue($profiler->begin($next));
        $profiler->finish($next);
    }

    public function testConsoleAndQueueProfilingAreOptIn(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        self::assertSame(false, $this->app->make(Repository::class)->get('tracewick.profile_console'));
        self::assertFalse($this->app->bound(ProfileCommand::class));
        self::assertSame(false, $this->app->make(Repository::class)->get('tracewick.profile_queue'));
        self::assertFalse($this->app->bound(ProfileJob::class));
    }

    #[TestWith([null])]
    #[TestWith([NullSamplerReason::Disabled])]
    public function testStatsReportsCountersInTheCurrentProcess(?NullSamplerReason $reason): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->instance(WorkProfiler::class, new WorkProfiler(new UnitOfWorkProfiler(
            $reason === null ? $this->createStub(Sampler::class) : new NullSampler($reason),
            $this->createStub(Sink::class),
        )));
        $command = $this->artisan('tracewick:stats');
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('Tracewick diagnostics for this PHP process')
            ->expectsTable(['Counter', 'Value'], [
                ['collectedProfiles', '0'],
                ['droppedProfiles', '0'],
                ['deliveredProfiles', '0'],
                ['rejectedProfiles', '0'],
                ['failedProfiles', '0'],
                ['skippedProfiles', '0'],
                ['emptyProfiles', '0'],
                ['swallowedErrors', '0'],
                ['samplerFailures', '0'],
                ['inactiveSampler', $reason->value ?? '-'],
            ])->assertSuccessful();
    }

    public function testConfigPublishTagMapsTheDistributedFileToTheApplication(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $paths = TracewickServiceProvider::pathsToPublish(TracewickServiceProvider::class, 'tracewick-config');

        self::assertCount(1, $paths);
        $source = array_key_first($paths);
        self::assertIsString($source);
        $expected = realpath(dirname(__DIR__) . '/config/tracewick.php');
        self::assertIsString($expected);
        self::assertSame($expected, realpath($source));
        self::assertSame([$this->app->configPath('tracewick.php')], array_values($paths));
    }

    public function testDisabledConfigurationIsANoop(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->make(Repository::class)->set('tracewick.enabled', false);
        $kernel = $this->app->make(Kernel::class);
        self::assertInstanceOf(\Illuminate\Foundation\Http\Kernel::class, $kernel);
        self::assertFalse($kernel->hasMiddleware(ProfileRequest::class));

        $this->get('/named/42')->assertOk();
        self::assertNull($this->app->make(HttpProfiler::class)->diagnostics());
        $command = $this->artisan('tracewick:stats');
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutput('Profiling is inactive.')->assertSuccessful();
    }
}
