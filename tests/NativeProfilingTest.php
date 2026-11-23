<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use Tracewick\Bridge\Laravel\HttpProfiler;
use Tracewick\Bridge\Laravel\ProfileRequest;
use Tracewick\Bridge\Laravel\TracewickServiceProvider;
use Tracewick\Sink\SpoolFile;

#[Group('excimer')]
final class NativeProfilingTest extends TestCase
{
    /** @return list<class-string<TracewickServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [TracewickServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        self::assertInstanceOf(Router::class, $router);
        $router->get('/items/{id}', static function (): string {
            $end = hrtime(true) + 150_000_000;
            while (hrtime(true) < $end) {
                hash('sha256', 'sampled work');
            }

            return 'ok';
        })->name('items.show');
    }

    public function testNamedProfileReachesTheSpoolOnlyAtTermination(): void
    {
        self::assertTrue(extension_loaded('excimer'), 'The excimer suite requires ext-excimer.');
        self::assertInstanceOf(Application::class, $this->app);
        $spool = sys_get_temp_dir() . '/tracewick-laravel-' . bin2hex(random_bytes(8));
        $this->app->make(Repository::class)->set([
            'tracewick.spool_directory' => $spool,
            'tracewick.sample_rate' => 1.0,
        ]);

        try {
            $kernel = $this->app->make(Kernel::class);
            $request = Request::create('/items/42?token=private');
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode());
            $other = Request::create('/other');
            $this->app->make(ProfileRequest::class)->terminate($other);
            self::assertDirectoryDoesNotExist($spool);
            $kernel->terminate($request, $response);
            $kernel->terminate($request, $response);

            $files = glob($spool . '/*/*.spool');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            $bytes = file_get_contents($files[0]);
            self::assertIsString($bytes);
            $profile = SpoolFile::fromBytes($bytes);
            self::assertSame('items.show', $profile->labels['route']);
            self::assertSame('GET', $profile->labels['method']);
            self::assertStringNotContainsString('token=private', $bytes);
            self::assertSame(1, $this->app->make(HttpProfiler::class)->diagnostics()?->deliveredProfiles);
        } finally {
            (new Filesystem())->deleteDirectory($spool);
        }
    }

    public function testUnavailableSpoolDoesNotChangeTheResponse(): void
    {
        self::assertTrue(extension_loaded('excimer'), 'The excimer suite requires ext-excimer.');
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->make(Repository::class)->set([
            'tracewick.spool_directory' => '/dev/null/tracewick',
            'tracewick.sample_rate' => 1.0,
        ]);

        $this->get('/items/42')->assertOk()->assertSee('ok');

        $diagnostics = $this->app->make(HttpProfiler::class)->diagnostics();
        self::assertNotNull($diagnostics);
        self::assertSame(1, $diagnostics->failedProfiles);
    }

}
