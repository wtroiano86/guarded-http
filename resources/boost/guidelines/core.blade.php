# Guarded HTTP

`wtroiano/guarded-http` is a standalone Laravel package providing policy-controlled HTTP requests with DNS pinning and bounded responses. Requires PHP 8.5+ within 8.x, Laravel components 13.34+ within 13.x, cURL, and mbstring. Installation uses Laravel's package discovery; configuration profiles belong to the consuming application.

## Use the pipelines

Prefer `Wtroiano\GuardedHttp\Pipelines\RequestProfilePipeline` and `RequestPipeline` in new integrations. Both are container-resolvable invokable classes. They use native PHP 8.5 composition and Guzzle middleware, not `Illuminate\Pipeline\Pipeline`.

`RequestProfilePipeline` accepts a configured profile name, a class implementing `Contracts\RequestProfile`, or a profile instance, and returns `Support\RequestSettings`. `RequestPipeline` accepts those settings, validates their limits, and returns Laravel's `PendingRequest`; sending happens when the caller invokes `get()`, `post()`, etc.

<code-snippet name="Send through a configured Guarded HTTP profile" lang="php">
use Wtroiano\GuardedHttp\Pipelines\RequestPipeline;
use Wtroiano\GuardedHttp\Pipelines\RequestProfilePipeline;

$request = 'automation'
    |> app(RequestProfilePipeline::class)
    |> app(RequestPipeline::class);

$response = $request->acceptJson()->get('https://automation.example.com/api');
$response->throw();
</code-snippet>

Constructor-inject the pipeline classes when used repeatedly inside application actions or other classes. For a one-off request, pass `new RequestSettings(policy: $policy, maximum: 1_048_576, timeout: 15, connectTimeout: 3)` directly to `RequestPipeline`.

`Services\GuardedHttp` is the compatible entry point for existing consumers: `profile($nameOrClassOrInstance)` and `request($policy, $maximum, $timeout = 30, $connectTimeout = 5, $limitMessage = 'HTTP response exceeds the byte limit.')` return the same `PendingRequest`. Its service provider is auto-discovered; register `Providers\GuardedHttpServiceProvider` manually only when discovery is disabled.

## Consumer-owned profiles

There are no built-in named profiles or default endpoint policies. Keep names, trust rules, and response budgets in the consuming application. Reuse its existing policy implementations and naming conventions. The `AutomationEndpointPolicy` below is an application class implementing `EndpointPolicy`, not a class shipped by this package.

For data-based profiles, create the application's `config/guarded-http.php`:

<code-snippet name="Configure a Guarded HTTP profile" lang="php">
use App\Http\Policies\AutomationEndpointPolicy;

return [
    'profiles' => [
        'automation' => [
            'policy' => AutomationEndpointPolicy::class,
            'maximum_bytes' => 8 * 1024 * 1024,
            'timeout_seconds' => 30,
            'connect_timeout_seconds' => 5,
            'limit_message' => 'Automation response exceeds its byte limit.',
        ],
    ],
];
</code-snippet>

- `policy` and `maximum_bytes` are required. `policy` must be a class implementing `EndpointPolicy`, or a compatible interface with a container binding. `maximum_bytes` must be a positive integer.
- Optional timeouts accept finite positive integers or floats in seconds, defaulting to 30 overall and 5 for connection. Connection timeout is capped at the overall timeout.
- `limit_message` defaults to `HTTP response exceeds the byte limit.` and must be a string. Unknown keys, numeric strings, and explicit null settings are rejected with `InvalidArgumentException` when creating a request.
- Configuration stores class names, not policy/profile objects or closures, so it can be cached. A named profile may instead map directly to a `RequestProfile` class name.
- Names match keys exactly and take precedence over class names. Unknown names fail; aliases to other profile names are unsupported. Each pipeline invocation reads the current configuration.

For class-based profiles, implement the interface; there is no abstract base profile to extend:

<code-snippet name="Define a class-based Guarded HTTP profile" lang="php">
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
        );
    }
}
</code-snippet>

Pass `AutomationProfile::class` or an instance to `RequestProfilePipeline`, or register `'automation' => AutomationProfile::class` under `guarded-http.profiles`. Classes resolve through Laravel's container and support constructor injection. `RequestSettings` uses constructor names `maximum`, `timeout`, `connectTimeout`, and `limitMessage`; these differ from the configuration keys.

## Endpoint policy and transport boundaries

- Implement `Wtroiano\GuardedHttp\Contracts\EndpointPolicy::resolve(string $url): array`, returning `array{host: string, port: int, address: string}`. Throw when the destination is not allowed. Bind interfaces used by configuration or constructor injection; concrete policy classes can resolve directly.
- The policy owns URL validation, permitted schemes/ports/hosts, DNS resolution, and private/reserved address rules. Validate every resolved address before choosing one, and return the supplied URL's host and port with the validated IP address. The transport trusts this result; it does not implement a public-address policy for you.
- Choose internal/private access explicitly in the application's policy. Do not substitute a test policy that accepts every URL for a production policy, and do not cache an earlier allow decision in place of per-request validation.
- Profiles select policy implementations; keys such as `endpoint`, `allowed_origins`, or `public_addresses_only` are not supported profile settings. Implement such rules in the policy.
- The fixed transport middleware order is `PinDestination`, then `BoundResponse`. The policy is called for each outgoing request, and cURL pins its result; a literal IPv6 destination is already its own pin. Redirects, proxies, and streaming mode are disabled. Declared response size, transport writes, and alternate-handler response reads are bounded.
- Each dispatch opens a fresh connection to the approved IP and closes it afterward. Reusing a `PendingRequest` does not reuse the previous destination's connection. Oversized writes abort cURL before raising the original limit exception, including for chunked and decompressed responses. Keep `allow_redirects` disabled: setting it to `true` or a nonempty options array throws `InvalidArgumentException` before dispatch. Handle redirect responses explicitly and validate any follow-up destination through the policy.
- Treat `PendingRequest` customization as trusted application code. Replacing the handler or middleware can bypass protections; this is not a sandbox for arbitrary options. Do not remove or reorder the transport stages to add authentication or headers.
- Authentication, request headers, status handling, and response interpretation belong to the caller. HTTP error statuses require explicit handling such as `throw()`; a response is not automatically a success. Limits and denied policies can throw exceptions before an HTTP response exists.
- The package adds no automatic retries. Retry writes only when the application's remote protocol guarantees safe repetition; a timeout does not establish that a remote write failed.

## Consumer tests

Use `Http::preventStrayRequests()` and `Http::fake()` for exact test URLs. Supply a deterministic policy or fake the policy's DNS resolver as well: HTTP fakes do not eliminate policy resolution. Test allowed and denied destinations, configured limits, and caller-specific status handling. Assert a denied policy sends nothing and oversized responses fail. These fakes exercise application behavior; they do not demonstrate real cURL/DNS enforcement.

Read this package's `README.md` for the complete public usage examples. Modify the consuming application's profiles and policies rather than files under `vendor/`.
