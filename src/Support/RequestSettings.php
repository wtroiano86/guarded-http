<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Support;

use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;

/**
 * Carries request configuration from a profile to the transport pipeline.
 *
 * Construction enforces PHP types only. RequestPipeline checks positive,
 * finite limits before preparing a request; this object does not resolve DNS.
 */
final readonly class RequestSettings
{
    /**
     * Set the response budget in bytes and both timeouts in seconds.
     *
     * The policy and maximum are required. Timeouts default to 30 seconds
     * overall and 5 for connecting; RequestPipeline caps the latter at the
     * overall timeout. limitMessage is used when a response exceeds the budget.
     */
    public function __construct(
        public EndpointPolicy $policy,
        public int $maximum,
        public float $timeout = 30,
        public float $connectTimeout = 5,
        public string $limitMessage = 'HTTP response exceeds the byte limit.',
    ) {}
}
