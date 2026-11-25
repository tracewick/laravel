<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Psr\Log\LoggerInterface;
use Tracewick\Profile\Labels;
use Tracewick\Runtime\ContinuousProfiler;
use Tracewick\Runtime\ContinuousProfilerConfig;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

final class ProfilerFactory
{
    public function __invoke(Application $app): WorkProfiler
    {
        return new WorkProfiler($this->build($app, $this->sequential(...), standalone: true));
    }

    public function forRoadRunner(Application $app): HttpProfiler
    {
        return new HttpProfiler(new WorkProfiler($this->build($app, $this->sequential(...))));
    }

    public function forSwoole(Application $app): ?ContinuousProfiler
    {
        return $this->build(
            $app,
            static fn(
                Repository $config,
                SpoolSink $sink,
                string $name,
                Labels $labels,
                SamplerConfig $sampler,
                LoggerInterface $logger,
            ): ContinuousProfiler => ContinuousProfiler::create(
                $sink,
                new ContinuousProfilerConfig($name, $labels),
                $sampler,
                $logger,
            ),
        );
    }

    /**
     * @template T of UnitOfWorkProfiler|ContinuousProfiler
     * @param \Closure(Repository, SpoolSink, string, Labels, SamplerConfig, LoggerInterface): T $create
     * @return T|null
     */
    private function build(Application $app, \Closure $create, bool $standalone = false): UnitOfWorkProfiler|ContinuousProfiler|null
    {
        $logger = null;

        try {
            $config = $app->make(Repository::class);

            if ($config->get('tracewick.enabled') !== true || ($standalone && (bool) Env::get('LARAVEL_OCTANE', false))) {
                return null;
            }

            $logger = $app->make(LoggerInterface::class);
            $name = $config->get('tracewick.application_name');
            $directory = $config->get('tracewick.spool_directory');
            $labels = $config->get('tracewick.base_labels');

            if (!is_string($name) || !is_string($directory) || !is_array($labels)) {
                throw new \InvalidArgumentException('Invalid Tracewick configuration.');
            }

            $baseLabels = [];
            foreach ($labels as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new \InvalidArgumentException('Tracewick base labels must have string keys and values.');
                }
                $baseLabels[$key] = $value;
            }

            return $create(
                $config,
                new SpoolSink($name, new SpoolConfig($directory)),
                $name,
                new Labels($baseLabels),
                new SamplerConfig(projectRoot: $app->basePath()),
                $logger,
            );
        } catch (\Throwable) {
            try {
                $logger?->warning('Tracewick could not initialize profiling. Check the package configuration.');
            } catch (\Throwable) {
                // A broken logger must not prevent the application from serving the request.
            }

            return null;
        }
    }

    private function sequential(
        Repository $config,
        SpoolSink $sink,
        string $name,
        Labels $labels,
        SamplerConfig $sampler,
        LoggerInterface $logger,
    ): UnitOfWorkProfiler {
        $rate = $config->get('tracewick.sample_rate');
        if (!is_numeric($rate)) {
            throw new \InvalidArgumentException('Invalid Tracewick sample rate.');
        }

        $profiler = UnitOfWorkProfiler::create($sink, new ProfilerConfig($labels, sampleRate: (float) $rate), $sampler, $logger);
        $profiler->registerShutdown();

        return $profiler;
    }
}
