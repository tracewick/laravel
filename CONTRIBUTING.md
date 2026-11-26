# Contributing to Tracewick Laravel

Use PHP 8.2–8.5 with `pdo_sqlite` and Composer 2. Run the checks before committing.

~~~bash
composer install
composer ci
~~~

CI runs mutation tests with Infection 0.35.8 and PCOV on PHP 8.5. With both tools
installed, run `composer infection`. The overall and covered mutation scores must
each reach 80%.

The test suite runs without Excimer. Laravel lifecycle tests use a test sampler and sink,
and the queue tests run Laravel's database driver against an in-memory SQLite database.
Native Excimer tests belong in [Tracewick core](https://github.com/tracewick/tracewick).

Open PRs from feature branches to develop with Conventional Commit titles. Keep release
changes on the release branch and let release-please generate the changelog.

Keep framework code here. Use the public Runtime and Sink APIs from Tracewick core, and
preserve the host application's HTTP responses when profiling fails.

## Releasing

The maintainer branches `release/<version>` from `develop` and opens a preparation PR
into `main`. The first candidate defaults to `1.0.0-rc.1`. Pin that version with
a Conventional Commit on the release branch.

```text
chore: prepare 1.0.0-rc.1

Release-As: 1.0.0-rc.1
```

1. Merge the preparation PR with a merge commit to preserve the release commit and footer.
2. Review the release-please PR and confirm the version and prerelease status. Merging
   it generates the tag, GitHub Release, and changelog through release-please.
3. Verify [signed release assets and SBOM](docs/verify-release.md), then verify
   a clean Laravel 12 and 13 install from Packagist. The first publication requires
   registration of the package on Packagist.
4. Merge the generated back-merge PR from `main` into `develop` with a merge commit.

The workflow uses the `tracewick-release` GitHub App. Give the App access to this
repository, configure the `RELEASE_APP_ID` Actions variable and
`RELEASE_APP_PRIVATE_KEY` secret, and include the App in the protected tag bypass
list. The App needs repository contents and pull request write permissions.

When preparing the final 1.0.0 release, switch to default versioning and set
`prerelease` to false in `release-please-config.json`. Release-please owns the manifest,
changelog and release tags.

Contributions are licensed under [Apache-2.0](LICENSE).
