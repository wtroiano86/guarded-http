<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Services;

use Illuminate\Http\Client\PendingRequest;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Contracts\RequestProfile;
use Wtroiano\GuardedHttp\Pipelines\RequestPipeline;
use Wtroiano\GuardedHttp\Pipelines\RequestProfilePipeline;
use Wtroiano\GuardedHttp\Support\RequestSettings;

/**
 * Provides the compatible request API by delegating to the package pipelines.
 *
 * Consumers can also compose RequestProfilePipeline and RequestPipeline directly.
 * Returned requests remain configurable by trusted application code.
 */
final readonly class GuardedHttp
{
    /**
     * Prepare a request from a configured name, profile class, or profile instance.
     *
     * Profile resolution and limit errors surface here. The destination policy
     * runs when the caller sends the returned PendingRequest.
     */
    public function profile(string|RequestProfile $profile): PendingRequest
    {
        return $profile
            |> resolve(RequestProfilePipeline::class)
            |> resolve(RequestPipeline::class);
    }

    /**
     * Prepare a request with an explicit byte budget and timeouts in seconds.
     *
     * Limits must be positive and finite; the connection timeout is capped at
     * the overall timeout. Authentication, sending, and status handling belong
     * to the caller. No automatic retry policy is added.
     */
    public function request(EndpointPolicy $policy, int $maximum, float $timeout = 30, float $connectTimeout = 5, string $limitMessage = 'HTTP response exceeds the byte limit.'): PendingRequest
    {
        return new RequestSettings($policy, $maximum, $timeout, $connectTimeout, $limitMessage)
            |> resolve(RequestPipeline::class);
    }
}
