# Guarded HTTP for Laravel

`wtroiano/guarded-http` is a standalone Laravel Composer package providing an HTTP transport with destination policies, DNS pinning, disabled redirects/proxies, and response byte limits.

| Requirement | Supported versions |
| --- | --- |
| PHP | 8.5 or later within 8.x |
| Laravel components | 13.34 or later within 13.x |
| PHP extensions | cURL and mbstring |

The initial public release is `v0.1.0`. See the [changelog](CHANGELOG.md) for release notes. Patch releases in the 0.1.x series preserve the documented public API; breaking changes use a new minor version.

## Why use this package?

Guarded HTTP is useful when your application sends requests to configurable destinations and needs consistent limits across integrations.

For example, a SaaS application may let customers enter webhook URLs. The application needs to control where requests go, how long they run, and how much response data it accepts. Guarded HTTP provides reusable enforcement:

- **Destination control:** your application's policy approves the destination for each outgoing request, and the transport pins the connection to the validated IP address.
- **Response limits:** oversized responses are rejected, including during transport writes and when reading responses from alternate handlers.
- **Controlled transport:** redirects and proxies are disabled, timeouts are enforced, and the package introduces no automatic retries.
- **Reusable profiles:** configuration arrays or classes group policies, timeouts, and byte budgets for different integrations.
- **Laravel integration:** callers retain familiar request methods, authentication headers, and HTTP fakes. Request behavior is composed through focused pipelines.

These safeguards are implemented and tested together, so applications can reuse them across webhooks, integrations, and downloads. The benefit is smaller for applications that only call a few fixed, trusted APIs.

Consumers must implement `EndpointPolicy`, including URL validation, DNS resolution, and address restrictions. Decide which hosts, schemes, ports, and private or reserved addresses your application permits. The transport trusts that policy's result; the package does not ship a default endpoint policy. Authentication, status handling, and response interpretation also remain with the caller.

## Installation

```bash
composer require wtroiano/guarded-http:^0.1
```

Laravel automatically discovers `Wtroiano\GuardedHttp\Providers\GuardedHttpServiceProvider`, which binds `Services\GuardedHttp` as a singleton. Applications that disable package discovery can register that provider in `bootstrap/providers.php`. Inject `GuardedHttp` into your services or resolve it from the container. Policies and limits can be supplied per request or through optional named profiles; no configuration publishing is required.

## Laravel Boost integration

The package ships consumer-facing AI guidance in [`resources/boost/guidelines/core.blade.php`](resources/boost/guidelines/core.blade.php), following [Laravel Boost's package guideline convention](https://laravel.com/framework/docs/boost#third-party-package-ai-guidelines). It covers pipeline composition, configuration and class-based profiles, endpoint-policy responsibilities, and testing.

In a consuming application with Laravel Boost installed, require `wtroiano/guarded-http` directly and run:

```bash
php artisan boost:install
```

Select this package's guidelines and the AI agents you use. Boost includes the guidance in its generated agent instructions, such as `AGENTS.md` or `CLAUDE.md`. Composer installation alone does not refresh those instructions. Boost 2.10 discovers third-party guidelines from direct dependencies; if Guarded HTTP arrived transitively, declare it directly to make it discoverable.

After package upgrades, run `php artisan boost:update` in the consuming application. Its interactive discovery can also select newly available package guidance; non-interactive updates refresh previously selected packages. Boost remains an optional development tool in the consumer and is not a runtime dependency of Guarded HTTP.

## Usage

```php
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Services\GuardedHttp;

// The application's policy validates the URL and every resolved address,
// then returns ['host' => ..., 'port' => ..., 'address' => ...].
$policy = app(EndpointPolicy::class);
$response = app(GuardedHttp::class)
    ->request($policy, maximum: 1_048_576, timeout: 15, connectTimeout: 3)
    ->acceptJson()
    ->get('https://service.example/api');
```

Implement and bind `EndpointPolicy` in the consuming application. The policy is responsible for trusted endpoint selection, allowed schemes/ports, DNS resolution, and private-address rules. The transport calls it for each request and pins the returned address. A literal IPv6 host is already its own pin. The policy must return the host and port of the supplied URL. Authentication, headers, response interpretation, and protocol-specific behavior remain with the caller.

No automatic retry is introduced. Byte limits apply during transport writes and when reading alternate-handler responses. `Support\BoundedResponseStream` is also available separately. A caller may pass `limitMessage` to retain its own error wording. Treat the returned `PendingRequest` as trusted application code: replacing its handler or middleware is not a security sandbox.

Each dispatch opens a fresh connection to the policy-approved IP and closes it afterward. This avoids stale destination reuse when a `PendingRequest` is sent more than once, at the cost of connection reuse. Oversized transport writes abort the download before the original limit exception is raised, including for chunked and decompressed responses. Setting `allow_redirects` to `true` or a nonempty options array throws `InvalidArgumentException` before dispatch. Handle any redirect response explicitly and submit its destination through the policy again.

## Request profiles

Profiles group an endpoint policy, response byte limit, timeouts, and optional limit error message. The existing `request()` API remains available. All profiles use the same transport validation, DNS pinning, and response limits.

For configuration profiles, add an application-owned `config/guarded-http.php`:

```php
use App\Http\Policies\AutomationEndpointPolicy;
use App\Http\Profiles\AutomationProfile;

return [
    'profiles' => [
        'automation' => [
            'policy' => AutomationEndpointPolicy::class,
            'maximum_bytes' => 8 * 1024 * 1024,
            'timeout_seconds' => 30,
            'connect_timeout_seconds' => 5,
            'limit_message' => 'Automation response exceeds its byte limit.',
        ],
        'automation-class' => AutomationProfile::class,
    ],
];
```

`policy` must name a class implementing `EndpointPolicy`, or an interface extending it with a container binding. `EndpointPolicy::class` itself may also be used when bound. `maximum_bytes` is required. Omitted timeouts default to 30 seconds overall and 5 seconds for connecting; the connection timeout is capped at the overall timeout. The default error message is `HTTP response exceeds the byte limit.` Unknown keys, invalid types, and nonpositive or nonfinite limits are rejected when creating the request. Numeric strings and explicit `null` values are not accepted. Use class names in configuration so it remains compatible with Laravel's configuration cache.

For class-based profiles, implement `RequestProfile` and return typed `RequestSettings`. Profile classes and configured policies resolve through Laravel's container:

```php
namespace App\Http\Profiles;

use App\Http\Policies\AutomationEndpointPolicy;
use Wtroiano\GuardedHttp\Contracts\RequestProfile;
use Wtroiano\GuardedHttp\Support\RequestSettings;

final readonly class AutomationProfile implements RequestProfile
{
    public function __construct(private AutomationEndpointPolicy $policy) {}

    public function settings(): RequestSettings
    {
        return new RequestSettings(
            policy: $this->policy,
            maximum: 8 * 1024 * 1024,
            timeout: 30,
            connectTimeout: 5,
            limitMessage: 'Automation response exceeds its byte limit.',
        );
    }
}
```

Select a configured name, a profile class, or an existing profile instance:

```php
$http->profile('automation')->acceptJson()->get($url);
$http->profile(AutomationProfile::class)->acceptJson()->get($url);
$http->profile(new AutomationProfile($policy))->acceptJson()->get($url);
```

Names match configuration keys exactly and take precedence over class names. Unknown names throw `InvalidArgumentException`; profiles cannot alias other profile names. Settings are read on each `profile()` call, and the policy still validates each outgoing request. Authentication and headers stay with the caller. Endpoint rules remain implemented by `EndpointPolicy`; profiles select a policy rather than interpret declarative DNS or address rules.

## Package development

The implementation uses ordered pipelines. `RequestProfilePipeline` passes the selected name or class through `ResolveProfileDefinition` and `BuildRequestSettings`. `RequestPipeline` validates the settings and installs the transport middleware in order: `PinDestination`, then `BoundResponse`. Destination validation runs for each outgoing request; response limits apply before and during transfer and when reading alternate-handler bodies. These transport stages have a fixed order.

The pipelines can also be injected or composed directly using PHP 8.5's pipe operator:

```php
use Wtroiano\GuardedHttp\Pipelines\RequestPipeline;
use Wtroiano\GuardedHttp\Pipelines\RequestProfilePipeline;

$request = 'automation'
    |> app(RequestProfilePipeline::class)
    |> app(RequestPipeline::class);

$response = $request->acceptJson()->get($url);
```

`Services\GuardedHttp` remains a thin compatibility entry point for `request()` and `profile()`; the request behavior lives in the pipelines. The pipelines use native PHP composition and Guzzle middleware, with no additional dependency.

This checkout follows a lightweight Laravel package layout:

```text
src/Contracts/             Endpoint policy contract
src/Providers/             Laravel service provider
src/Pipelines/             Request and profile pipelines
src/Pipelines/Profiles/    Profile resolution and settings stages
src/Pipelines/Transport/   Destination and response middleware
src/Services/              Compatible GuardedHttp entry point
src/Support/               Request settings and response streams
tests/Feature/Providers/   Provider integration tests
tests/Feature/Services/    Transport behavior tests
tests/Feature/Pipelines/   Profile and pipeline integration tests
tests/Unit/Support/        Isolated stream tests
tests/Fixtures/            Deterministic policies and a local HTTP server
tests/TestCase.php         Orchestra Testbench application
tests/Pest.php             Pest test bindings
```

Use a Composer path repository for local development. Run these commands from this checkout:

```bash
composer install
composer test

# Run a focused test without the complete quality gate:
composer test:unit -- --filter="test description"
```

Composer prepares Testbench's package discovery after autoload generation. Feature tests boot a Laravel application through Testbench 11. Most use deterministic HTTP fakes/handlers; transport regression tests start a PHP subprocess listening on `127.0.0.1` and `127.0.0.2` at an ephemeral port. They require subprocess execution and loopback sockets, use no remote services, and stop the server after each test. Unit tests run without booting Laravel. The package's CI runs the complete quality gate. Testbench and the full framework are development dependencies only.

`composer test` enforces Pint and Rector, PHPStan at `max` with Larastan, Carbon, and bleeding-edge rules, 100% type coverage, architecture rules, and exactly 100% line coverage across all `src` files. Coverage requires PCOV or Xdebug in coverage mode; CI installs PCOV. `composer lint` applies the PHP formatting and refactoring rules. Individual checks remain available as `test:lint`, `test:types`, `test:type-coverage`, and `test:coverage`.

CI runs behavior and coverage checks against both the lowest and latest supported dependencies, with formatting and static analysis on the latest supported tooling. It also audits runtime dependencies and installs the release archive in a clean Laravel application. Run `composer test:distribution` locally to check archive contents, automatic provider discovery, and profile composition without development dependencies in the consumer. This check requires a POSIX shell and PHP's zip extension and removes its temporary files afterward.

## Support and security

The source repository is [wtroiano86/guarded-http](https://github.com/wtroiano86/guarded-http). Use the [issue tracker](https://github.com/wtroiano86/guarded-http/issues) for ordinary bugs and feature requests.

Send vulnerability reports and security notifications privately to [opensource.troiano86@pm.me](mailto:opensource.troiano86@pm.me). See the [security policy](SECURITY.md) for reporting details.

## License

Guarded HTTP is released under the [MIT license](LICENSE).
