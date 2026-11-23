<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tracewick\Runtime\Diagnostics;
use Tracewick\Runtime\UnitOfWorkProfiler;

/** Tracks the request that owns the active unit of work. */
final class HttpProfiler
{
    private ?Request $activeRequest = null;

    public function __construct(private readonly ?UnitOfWorkProfiler $profiler) {}

    public function begin(Request $request): bool
    {
        if ($this->profiler === null || $this->activeRequest !== null) {
            return false;
        }

        try {
            $rawMethod = $request->getRealMethod();
        } catch (\Throwable) {
            $rawMethod = '';
        }
        $method = match ($rawMethod) {
            'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE' => $rawMethod,
            default => 'OTHER',
        };

        $this->profiler->start(['route' => 'unmatched', 'method' => $method]);
        $this->activeRequest = $request;

        return true;
    }

    public function finish(Request $request): void
    {
        if ($this->profiler === null || $this->activeRequest !== $request) {
            return;
        }

        try {
            $route = $request->route();
            if ($route instanceof Route) {
                $this->profiler->label('route', $route->getName() ?? 'unnamed');
            }
        } catch (\Throwable) {
            // A custom route resolver can fail. Keep the initial route label.
        } finally {
            $this->profiler->stop();
            $this->profiler->flush();
            $this->activeRequest = null;
        }
    }

    public function diagnostics(): ?Diagnostics
    {
        return $this->profiler?->diagnostics();
    }
}
