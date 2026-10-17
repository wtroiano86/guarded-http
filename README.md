# Guarded HTTP for Laravel

`wtroiano/guarded-http` provides a Laravel HTTP transport with destination policies, DNS pinning, disabled redirects/proxies, and response byte limits. Requires PHP 8.5, Laravel components 13.34+, and cURL. It has no Helm dependency or service provider.

```php
use Wtroiano\GuardedHttp\EndpointPolicy;
use Wtroiano\GuardedHttp\GuardedHttp;

// The application's policy validates the URL and every resolved address,
// then returns ['host' => ..., 'port' => ..., 'address' => ...].
$policy = app(EndpointPolicy::class);
$response = app(GuardedHttp::class)
    ->request($policy, maximum: 1_048_576, timeout: 15, connectTimeout: 3)
    ->acceptJson()
    ->get('https://service.example/api');
```

Implement and bind `EndpointPolicy` in the consuming application. The policy is responsible for trusted endpoint selection, allowed schemes/ports, DNS resolution, and private-address rules. The transport calls it for each request and pins the returned address. A literal IPv6 host is already its own pin. The policy must return the host and port of the supplied URL. Authentication, headers, response interpretation, and protocol-specific behavior remain with the caller.

No automatic retry is introduced. Byte limits apply during transport writes and when reading alternate-handler responses. `BoundedResponseStream` is also available separately. A caller may pass `limitMessage` to retain its own error wording. Treat the returned `PendingRequest` as trusted application code: replacing its handler or middleware is not a security sandbox.

Install with `composer require wtroiano/guarded-http:^0.1`. For local development, use a Composer path repository. Run `composer install`, `composer test`, `composer test:types`, and `composer test:lint` from this checkout. Tests use deterministic endpoint policies and HTTP fakes/handlers without connecting to remote services.
