<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Input\InputInterface;

final class ProfileCommand
{
    private const EXCLUDED_COMMANDS = [
        '',
        'horizon',
        'pail',
        'queue:listen',
        'queue:work',
        'reverb:start',
        'schedule:work',
        'serve',
        'tinker',
    ];

    private ?InputInterface $outerInput = null;
    private ?WorkProfiler $profiler = null;

    public function __construct(private readonly Application $app) {}

    public function starting(CommandStarting $event): void
    {
        if ($this->outerInput !== null) {
            return;
        }

        // An excluded top-level runner also owns its nested commands.
        $this->outerInput = $event->input;
        $name = $event->command;
        if (\in_array($name, self::EXCLUDED_COMMANDS, true)
            || str_starts_with($name, 'horizon:')
            || str_starts_with($name, 'octane:')
            || str_starts_with($name, 'tracewick:')
        ) {
            return;
        }

        try {
            $profiler = $this->app->make(WorkProfiler::class);
            if ($profiler->begin($event->input, ['command' => $name])) {
                $this->profiler = $profiler;
            }
        } catch (\Throwable) {
            // Keep command execution independent of the profiler binding.
        }
    }

    public function finished(CommandFinished $event): void
    {
        if ($this->outerInput !== $event->input) {
            return;
        }

        $this->profiler?->finish($event->input);
        $this->profiler = null;
        $this->outerInput = null;
    }
}
