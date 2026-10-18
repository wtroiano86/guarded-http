<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Pipelines;

use Wtroiano\GuardedHttp\Contracts\RequestProfile;
use Wtroiano\GuardedHttp\Pipelines\Profiles\BuildRequestSettings;
use Wtroiano\GuardedHttp\Pipelines\Profiles\ResolveProfileDefinition;
use Wtroiano\GuardedHttp\Support\RequestSettings;

/**
 * Resolves a consumer profile and converts its definition into typed settings.
 *
 * Definition lookup precedes settings construction. This pipeline does not
 * validate numeric ranges, resolve a destination, or send an HTTP request.
 */
final readonly class RequestProfilePipeline
{
    public function __construct(private ResolveProfileDefinition $resolveDefinition, private BuildRequestSettings $buildSettings) {}

    /**
     * Build settings from a configured name, profile class, or existing instance.
     *
     * Unknown names and malformed definitions raise InvalidArgumentException.
     * Container and profile exceptions propagate. Pass the result to
     * RequestPipeline for limit validation and transport preparation.
     */
    public function __invoke(string|RequestProfile $profile): RequestSettings
    {
        return $profile
            |> $this->resolveDefinition->__invoke(...)
            |> $this->buildSettings->__invoke(...);
    }
}
