<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as LaravelKernel;
use Illuminate\Support\ServiceProvider;

final class TracewickServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/tracewick.php', 'tracewick');
        $this->app->singleton(WorkProfiler::class, static fn(Application $app): WorkProfiler => (new ProfilerFactory())($app));
        $this->app->singleton(HttpProfiler::class);
    }

    public function boot(Repository $config, Dispatcher $events): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/tracewick.php' => $this->app->configPath('tracewick.php'),
            ], 'tracewick-config');
            $this->commands([StatsCommand::class]);
            if ($config->get('tracewick.profile_console') === true) {
                $this->app->singleton(ProfileCommand::class);
                $events->listen(CommandStarting::class, [ProfileCommand::class, 'starting']);
                $events->listen(CommandFinished::class, [ProfileCommand::class, 'finished']);
            }
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
