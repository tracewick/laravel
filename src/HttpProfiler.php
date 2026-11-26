<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tracewick\Runtime\Diagnostics;

/** Tracks the request that owns the active unit of work. */
final readonly class HttpProfiler
{
    public function __construct(private WorkProfiler $profiler) {}

    public function begin(Request $request): bool
    {
        if (!$this->profiler->canBegin()) {
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

        return $this->profiler->begin($request, ['route' => 'unmatched', 'method' => $method]);
    }

    public function finish(Request $request): void
    {
        if (!$this->profiler->owns($request)) {
            return;
        }

        try {
            $route = $request->route();
            if ($route instanceof Route) {
                $this->profiler->label($request, 'route', $route->getName() ?? 'unnamed');
            }
        } catch (\Throwable) {
            // A custom route resolver can fail. Keep the initial route label.
        } finally {
            $this->profiler->finish($request);
        }
    }

    public function finishActiveRequest(): void
    {
        $request = $this->profiler->owner();
        if ($request instanceof Request) {
            $this->finish($request);
        }
    }

    public function diagnostics(): ?Diagnostics
    {
        return $this->profiler->diagnostics();
    }
}
