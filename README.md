# Tracewick Laravel

Laravel bridge for [Tracewick](https://github.com/tracewick/tracewick). It profiles
PHP-FPM HTTP requests and writes sampled profiles to a local spool.

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
also apply. Octane workers leave the HTTP profiler inactive. Command and queue profiling
are planned for later milestones.

During development, applications testing against the core release candidate must explicitly
require `tracewick/tracewick:1.0.0-rc.1` while keeping their normal Composer stability setting.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md).
