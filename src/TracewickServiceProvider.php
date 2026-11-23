<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Support\ServiceProvider;

final class TracewickServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tracewick.php', 'tracewick');
        $this->app->singleton(HttpProfiler::class, static fn(Application $app): HttpProfiler => (new ProfilerFactory())($app));
    }

    public function boot(Repository $config): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/tracewick.php' => $this->app->configPath('tracewick.php'),
            ], 'tracewick-config');
            $this->commands([StatsCommand::class]);
        }

        $this->callAfterResolving(Kernel::class, static function (Kernel $kernel) use ($config): void {
            if ($kernel instanceof LaravelKernel
                && $config->get('tracewick.enabled') === true
                && $config->get('tracewick.register_middleware') === true
            ) {
                $kernel->prependMiddleware(ProfileRequest::class);
            }
        });
    }
}
