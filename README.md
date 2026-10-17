# Guarded HTTP for Laravel

`wtroiano/guarded-http` is a Laravel Composer package providing an HTTP transport with destination policies, DNS pinning, disabled redirects/proxies, and response byte limits. Requires PHP 8.5, Laravel components 13.34+, and cURL. It has no Helm dependency.

## Installation

```bash
composer require wtroiano/guarded-http:^0.1
```

Laravel automatically discovers `Wtroiano\GuardedHttp\Providers\GuardedHttpServiceProvider`, which binds `Services\GuardedHttp` as a singleton. Applications that disable package discovery can register that provider in `bootstrap/providers.php`. Inject `GuardedHttp` into your services or resolve it from the container. Policies and limits are explicit per request, so the package needs no published configuration.

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

## Package development

This checkout follows a lightweight Laravel package layout:

```text
src/Contracts/             Endpoint policy contract
src/Providers/             Laravel service provider
src/Services/              Guarded HTTP transport
src/Support/               Bounded response stream
tests/Feature/Providers/   Provider integration tests
tests/Feature/Services/    Transport behavior tests
tests/Unit/Support/        Isolated stream tests
tests/Fixtures/            Deterministic endpoint policies
tests/TestCase.php         Orchestra Testbench application
tests/Pest.php             Pest test bindings
```

Use a Composer path repository for local development. Run these commands from this checkout:

```bash
composer install
composer test
composer test:types
composer test:type-coverage
composer test:lint
```

Composer prepares Testbench's package discovery after autoload generation. Feature tests boot a Laravel application through Testbench 11 and use deterministic HTTP fakes/handlers without connecting to remote services. Unit tests run without booting Laravel. CI runs these same checks. The package requires no Helm workbench; Testbench and the full framework are development dependencies only.
