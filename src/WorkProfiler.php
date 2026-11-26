<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Tracewick\Runtime\Diagnostics;
use Tracewick\Runtime\UnitOfWorkProfiler;

/** Holds the one active unit of work for the Laravel application. */
final class WorkProfiler
{
    private ?object $owner = null;

    public function __construct(private readonly ?UnitOfWorkProfiler $profiler) {}

    public function canBegin(): bool
    {
        return $this->profiler !== null && $this->owner === null;
    }

    /** @param array<string, string> $labels */
    public function begin(object $owner, array $labels): bool
    {
        if (!$this->canBegin()) {
            return false;
        }

        $this->profiler?->start($labels);
        $this->owner = $owner;

        return true;
    }

    public function owns(object $owner): bool
    {
        return $this->owner === $owner;
    }

    public function owner(): ?object
    {
        return $this->owner;
    }

    public function label(object $owner, string $key, string $value): void
    {
        if ($this->owns($owner)) {
            $this->profiler?->label($key, $value);
        }
    }

    public function finish(object $owner): void
    {
        if (!$this->owns($owner)) {
            return;
        }

        $this->profiler?->stop();
        $this->profiler?->flush();
        $this->owner = null;
    }

    public function diagnostics(): ?Diagnostics
    {
        return $this->profiler?->diagnostics();
    }
}
