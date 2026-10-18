<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Pipelines\Profiles;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Contracts\RequestProfile;
use Wtroiano\GuardedHttp\Support\RequestSettings;

/**
 * Converts a selected configuration definition or profile into request settings.
 *
 * Array definitions are checked for supported keys and types, and their policy
 * is resolved through the container. RequestPipeline validates numeric ranges.
 */
final readonly class BuildRequestSettings
{
    public function __construct(private Container $container) {}

    /**
     * Evaluate a class profile or normalize the configuration settings.
     *
     * Arrays require policy and maximum_bytes. Omitted timeouts default to
     * 30 seconds overall and 5 for connecting. Optional keys are timeout_seconds,
     * connect_timeout_seconds, and limit_message; explicit nulls and numeric
     * strings are rejected. Profile and container exceptions propagate.
     *
     * @param  array<array-key, mixed>|RequestProfile  $definition
     *
     * @throws InvalidArgumentException When array settings or the policy are invalid.
     */
    public function __invoke(array|RequestProfile $definition): RequestSettings
    {
        if ($definition instanceof RequestProfile) {
            return $definition->settings();
        }

        throw_if(array_diff(array_keys($definition), ['policy', 'maximum_bytes', 'timeout_seconds', 'connect_timeout_seconds', 'limit_message']) !== [], InvalidArgumentException::class, 'Unknown HTTP profile setting.');

        $maximum = $definition['maximum_bytes'] ?? null;
        $timeout = array_key_exists('timeout_seconds', $definition) ? $definition['timeout_seconds'] : 30;
        $connectTimeout = array_key_exists('connect_timeout_seconds', $definition) ? $definition['connect_timeout_seconds'] : 5;
        $limitMessage = array_key_exists('limit_message', $definition) ? $definition['limit_message'] : 'HTTP response exceeds the byte limit.';

        throw_unless(is_int($maximum) && (is_int($timeout) || is_float($timeout))
            && (is_int($connectTimeout) || is_float($connectTimeout)) && is_string($limitMessage),
            InvalidArgumentException::class, 'HTTP profiles require an integer maximum_bytes, numeric timeouts, and a string limit_message.');

        $policy = $definition['policy'] ?? null;
        throw_unless(is_string($policy) && is_a($policy, EndpointPolicy::class, true), InvalidArgumentException::class, 'HTTP profiles require an EndpointPolicy class.');
        $resolved = $this->container->make($policy);
        throw_unless($resolved instanceof EndpointPolicy, InvalidArgumentException::class, 'The resolved HTTP policy must implement EndpointPolicy.');

        return new RequestSettings($resolved, $maximum, $timeout, $connectTimeout, $limitMessage);
    }
}
