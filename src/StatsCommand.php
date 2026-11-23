<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Console\Command;

final class StatsCommand extends Command
{
    protected $signature = 'tracewick:stats';

    protected $description = 'Show Tracewick counters for this PHP process';

    public function handle(HttpProfiler $profiler): int
    {
        $this->line('Tracewick diagnostics for this PHP process');
        $diagnostics = $profiler->diagnostics();

        if ($diagnostics === null) {
            $this->line('Profiling is inactive.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach (get_object_vars($diagnostics) as $name => $value) {
            if (is_int($value)) {
                $rows[] = [$name, (string) $value];
            }
        }
        $rows[] = ['inactiveSampler', $diagnostics->inactiveSampler->value ?? '-'];

        $this->table(['Counter', 'Value'], $rows);

        return self::SUCCESS;
    }
}
