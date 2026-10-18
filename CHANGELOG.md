# Changelog

## 0.1.0 (unreleased)

Initial public release of Guarded HTTP for Laravel.

### Added

- Consumer-owned endpoint policies with per-request destination validation and cURL address pinning.
- Response byte budgets enforced through declared lengths, transport writes, and alternate-handler body reads.
- Validated request and connection timeouts, disabled redirects and proxies, and no automatic retries.
- Configuration and class-based request profiles with typed request settings.
- Composable request and profile pipelines, plus the `GuardedHttp` convenience entry point.
- Laravel service-provider discovery and compatibility with Laravel HTTP fakes.
- Laravel Boost guidelines for consuming applications.

### Security

- Open a fresh connection for every dispatch so reused requests honor the latest policy-selected IP.
- Abort cURL on response write failures before propagating the original exception, including byte limits on chunked and decompressed bodies.
- Reject redirect-enabling request options before dispatch and disable native cURL redirects.

### Requirements

- PHP 8.5 or later within PHP 8.x, with cURL and mbstring.
- Laravel 13.34 or later within Laravel 13.x.
- An application-supplied `EndpointPolicy` implementing destination validation and DNS/address rules.

The 0.1.x series will preserve the documented public API. Breaking changes during initial development will use a new minor version and be described here.
