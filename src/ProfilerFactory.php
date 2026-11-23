<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Psr\Log\LoggerInterface;
use Tracewick\Profile\Labels;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

final class ProfilerFactory
{
    public function __invoke(Application $app): WorkProfiler
    {
        $logger = null;

        try {
            $config = $app->make(Repository::class);

            if ($config->get('tracewick.enabled') !== true || (bool) Env::get('LARAVEL_OCTANE', false)) {
                return new WorkProfiler(null);
            }

            $logger = $app->make(LoggerInterface::class);
            $name = $config->get('tracewick.application_name');
            $directory = $config->get('tracewick.spool_directory');
            $rate = $config->get('tracewick.sample_rate');
            $labels = $config->get('tracewick.base_labels');

            if (!is_string($name) || !is_string($directory) || !is_numeric($rate) || !is_array($labels)) {
                throw new \InvalidArgumentException('Invalid Tracewick configuration.');
            }

            $baseLabels = [];
            foreach ($labels as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new \InvalidArgumentException('Tracewick base labels must have string keys and values.');
                }
                $baseLabels[$key] = $value;
            }

            $profiler = UnitOfWorkProfiler::create(
                new SpoolSink($name, new SpoolConfig($directory)),
                new ProfilerConfig(new Labels($baseLabels), sampleRate: (float) $rate),
                new SamplerConfig(projectRoot: $app->basePath()),
                logger: $logger,
            );
            $profiler->registerShutdown();

            return new WorkProfiler($profiler);
        } catch (\Throwable) {
            try {
                $logger?->warning('Tracewick could not initialize profiling. Check the package configuration.');
            } catch (\Throwable) {
                // A broken logger must not prevent the application from serving the request.
            }

            return new WorkProfiler(null);
        }
    }
}
