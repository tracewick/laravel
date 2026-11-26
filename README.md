# Tracewick Laravel

Laravel bridge for [Tracewick](https://github.com/tracewick/tracewick). It profiles
HTTP requests by default and writes sampled profiles to a local spool. Artisan
commands, queue attempts, and Octane profiling are opt-in.

The package is in development. The initial compatibility targets are Laravel 12 on PHP
8.2–8.5 and Laravel 13 on PHP 8.3–8.5. Collecting samples requires
[ext-excimer](https://www.mediawiki.org/wiki/Excimer). Requests continue to work without the extension.

## Configuration

Composer package discovery registers Tracewick automatically. If you disabled discovery,
add `Tracewick\Bridge\Laravel\TracewickServiceProvider::class` to `bootstrap/providers.php`.

~~~bash
php artisan vendor:publish --tag=tracewick-config
~~~

Set the app and spool directory in your Laravel environment.

~~~dotenv
TRACEWICK_APPLICATION=my-laravel-app
TRACEWICK_SPOOL_DIR=/var/spool/tracewick
TRACEWICK_SAMPLE_RATE=0.1
TRACEWICK_LARAVEL_ENABLED=true
~~~

Rebuild any configuration cache after changing these settings. Set `register_middleware`
to false in `config/tracewick.php` if you register the global middleware yourself. In that
case prepend `Tracewick\Bridge\Laravel\ProfileRequest::class` to the global middleware
stack in `bootstrap/app.php`.

The profiler starts before routing and keeps the HTTP method and the matched route's name.
Requests without a matched route use `unmatched`. Matched routes without a name use `unnamed`.
URI, query strings and request parameters are excluded from the bridge's labels. Keep
`base_labels` static and free of account identifiers or other sensitive data.

A request hands its profile to the spool when Laravel terminates the HTTP request. Use a
local spool directory writable by the PHP worker and accessible to one delivery process.
Use [tracewick-drain](https://github.com/tracewick/tracewick/blob/main/docs/drain.md) or the
[Go agent](https://github.com/tracewick/agent). Configure the reader's environment
separately from the Laravel application.

For application diagnostics run `php artisan tracewick:stats`. It shows the counters of
that invocation and cannot read counters from PHP-FPM workers. The
[core kill switches](https://github.com/tracewick/tracewick/blob/main/docs/runtime.md)
also apply.

## Artisan

Set `TRACEWICK_PROFILE_CONSOLE=true` and rebuild the configuration cache if you use one.
Eligible Artisan invocations share the same sample rate, spool, and base labels as HTTP
requests. Each profile uses the registered command name. Nested commands run within the
outer profile. Commands launched from a web request stay within the HTTP profile.

Tracewick commands, queue workers and listeners, Horizon, Octane, and other built-in
long-running commands such as `serve` and `schedule:work` are excluded.

## Queue and Horizon

Set `TRACEWICK_PROFILE_QUEUE=true` in the worker process environment. Rebuild any
configuration cache and restart the workers so they pick up the change.

The bridge uses the same profiling setup for `queue:work` and Horizon workers. Each
completed attempt can produce one profile, including releases and failures that the
worker handles. The job label uses the loaded job class or `unknown`. Synchronous jobs
stay within the current request, command, or asynchronous job profile.

The worker flushes when Laravel emits `JobAttempted`. A graceful stop lets the in-flight
attempt reach that event. A hard timeout or forced kill can end the process before
that event, so the in-flight profile can be lost. Profiles already written to the spool
remain available to the reader.

## Octane

Install Laravel Octane 2.24 or later in your application and set
`TRACEWICK_OCTANE_MODE` to match the server. The default is `off`. Rebuild any
configuration cache and restart the workers after changing the mode.

| Mode | Scope | Labels and sampling |
| --- | --- | --- |
| `roadrunner` | One profile per sampled HTTP request | Uses the HTTP method, Laravel route name, and `TRACEWICK_SAMPLE_RATE`. |
| `swoole` | Continuous profile per Swoole or OpenSwoole HTTP worker | Uses static `base_labels`. All work in the HTTP worker can contribute, including concurrent requests and activity between requests. |

Octane owns the profiling lifecycle in both modes. RoadRunner reuses its sampler
across requests and finalizes an active profile when Octane releases a request
after an error. Swoole and OpenSwoole capture segments when Octane releases
completed requests, at most once per second. During idle periods, delivery
waits until a request completes or the worker exits gracefully. Graceful worker
exit flushes all remaining samples. Task workers are excluded. Octane's
Swoole stop command forcibly kills the processes, so it can lose samples still
held by a worker. The Laravel and core kill switches also apply to Octane.

During development, applications testing against the core release candidate must explicitly
require `tracewick/tracewick:1.0.0-rc.1` while keeping their normal Composer stability setting.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md).
