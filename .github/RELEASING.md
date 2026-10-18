# Releasing Guarded HTTP

The first public release targets `v0.1.0`. Composer derives the package version from Git tags; leave the `version` field out of `composer.json`.

## Prepare a release

1. Review all pending changes and update `CHANGELOG.md`. Replace the unreleased label with the version's actual release date when publishing.
2. Confirm the PHP and Laravel requirements in `composer.json`, the README, and the changelog agree. The 0.1.x public API includes the documented contracts, settings, pipelines, and `GuardedHttp` methods; preserve it in patch releases.
3. Run the quality gate and the archive installation check:

   ```bash
   composer validate --strict
   composer install
   composer audit --locked --no-dev
   composer test
   composer test:distribution
   ```

   Coverage needs PCOV or Xdebug coverage mode. For Xdebug, use `XDEBUG_MODE=coverage composer test`. The distribution check requires a POSIX shell and PHP's zip extension, creates a temporary consumer application under `tmp`, and cleans it up on exit. It installs the actual archive with no development dependencies and verifies Laravel's automatic package discovery, profile composition, and HTTP fakes.

4. Confirm CI passes for both lowest and latest supported dependencies. Both sets run behavioral tests and coverage; formatting and static analysis use the latest supported tooling. Older PHPStan releases do not infer the returned middleware closure's option-array type, so the minimum-dependency job checks runtime compatibility separately. Review the full development dependency audit with `composer audit --locked`; development tooling is not installed by consumers.
5. Review and commit the intended release files, including `resources/boost/guidelines/core.blade.php`. Confirm `git status --short` is clean before tagging. The archive check uses the working tree; the published tag includes only committed files.

The minimum-dependency run currently reports PHP 8.5 deprecations in older `symfony/translation` and `phpdocumentor/reflection-docblock` releases; its behavior assertions and coverage pass. The development audit also reports the transitive `symplify/rule-doc-generator-contracts` package as abandoned. The runtime dependency audit is clean. Reassess these findings before each release without suppressing dependency advisories.

## Public repository

The source repository is [wtroiano86/guarded-http](https://github.com/wtroiano86/guarded-http). Confirm this checkout's `origin` points to `https://github.com/wtroiano86/guarded-http.git` before pushing. Composer's homepage, source, issue tracker, and security-policy links refer to this repository.

The security contact is [opensource.troiano86@pm.me](mailto:opensource.troiano86@pm.me), documented in `SECURITY.md` and included in the release archive.

Push the reviewed branch and wait for the package CI workflow to pass on that commit. Configure required CI checks on the release branch.

## Publish v0.1.0

These commands publish a release and should only be run once the reviewed commit is ready:

```bash
git tag -a v0.1.0 -m "Release v0.1.0"
git push origin v0.1.0
```

Create the hosting platform's release for that tag using the `0.1.0` changelog entry. Submit the public repository URL to [Packagist](https://packagist.org/packages/submit) and configure its repository integration for automatic updates. Verify Packagist lists version `0.1.0` and that a fresh Laravel application can run:

```bash
composer require wtroiano/guarded-http:^0.1
```

Check that the installed package contains its license, README, changelog, security policy, source classes, and `resources/boost/guidelines/core.blade.php`. In a consumer using Laravel Boost, select the package during `php artisan boost:install`.

For subsequent releases, update the changelog and tag a new version. Published tags should stay immutable; ship a new patch version for fixes.
