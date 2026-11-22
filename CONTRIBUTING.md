# Contributing to Tracewick Laravel

Use PHP 8.2–8.5 and Composer 2. Run the checks before committing.

~~~bash
composer install
composer ci
~~~

The bootstrap smoke test runs without Excimer. Before submitting a change to sampling,
also verify that path with real Excimer.

Open PRs from feature branches to develop with Conventional Commit titles. Keep release
changes on the release branch and let release-please generate the changelog.

Keep framework code here. Use the public Runtime and Sink APIs from Tracewick core, and
preserve the host application's HTTP responses when profiling fails.

Contributions are licensed under [Apache-2.0](LICENSE).
