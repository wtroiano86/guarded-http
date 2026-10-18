<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Pipelines;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Wtroiano\GuardedHttp\Pipelines\Transport\BoundResponse;
use Wtroiano\GuardedHttp\Pipelines\Transport\PinDestination;
use Wtroiano\GuardedHttp\Support\RequestSettings;

/**
 * Prepares the HTTP transport with destination pinning followed by byte bounds.
 *
 * Middleware executes when requests are sent, so each dispatch resolves its
 * destination and creates its own response buffer. Handler and middleware
 * customization is trusted application code, not a security sandbox.
 */
final readonly class RequestPipeline
{
    /**
     * Validate settings and return a PendingRequest without sending it.
     *
     * Budgets are bytes and timeouts are seconds. Cap connection time at the
     * overall timeout and install guards in a fixed order. Redirects, proxies,
     * and streaming mode are disabled; no retry policy is added.
     *
     * @throws InvalidArgumentException When a limit is nonpositive or nonfinite.
     */
    public function __invoke(RequestSettings $settings): PendingRequest
    {
        throw_if($settings->maximum < 1 || ! is_finite($settings->timeout) || $settings->timeout <= 0
            || ! is_finite($settings->connectTimeout) || $settings->connectTimeout <= 0,
            InvalidArgumentException::class, 'Response limits and HTTP timeouts must be positive and finite.');

        return Http::timeout($settings->timeout)->connectTimeout(min($settings->connectTimeout, $settings->timeout))
            ->withOptions(['allow_redirects' => false, 'stream' => false, 'proxy' => ''])
            ->withMiddleware(new PinDestination($settings->policy))
            ->withMiddleware(new BoundResponse($settings->maximum, $settings->limitMessage))
            ->setHandler(new CurlHandler);
    }
}
