<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProfileRequest
{
    public function __construct(private HttpProfiler $profiler) {}

    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next): Response
    {
        $ownsProfile = $this->profiler->begin($request);

        try {
            return $next($request);
        } catch (\Throwable $error) {
            if ($ownsProfile) {
                $this->profiler->finish($request);
            }

            throw $error;
        }
    }

    public function terminate(Request $request): void
    {
        $this->profiler->finish($request);
    }
}
