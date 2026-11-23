# Contributing to Tracewick Laravel

Use PHP 8.2–8.5 and Composer 2. Run the checks before committing.

~~~bash
composer install
composer ci
~~~

The test suite runs without Excimer. Laravel lifecycle tests use a test sampler and sink.
Native Excimer tests belong in [Tracewick core](https://github.com/tracewick/tracewick).

Open PRs from feature branches to develop with Conventional Commit titles. Keep release
changes on the release branch and let release-please generate the changelog.

Keep framework code here. Use the public Runtime and Sink APIs from Tracewick core, and
preserve the host application's HTTP responses when profiling fails.

Contributions are licensed under [Apache-2.0](LICENSE).
