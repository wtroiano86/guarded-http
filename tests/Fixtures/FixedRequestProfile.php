<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Tests\Fixtures;

use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Contracts\RequestProfile;
use Wtroiano\GuardedHttp\Support\RequestSettings;

final readonly class FixedRequestProfile implements RequestProfile
{
    public function __construct(private EndpointPolicy $policy) {}

    public function settings(): RequestSettings
    {
        return new RequestSettings($this->policy, maximum: 4, timeout: 2.5, connectTimeout: 3, limitMessage: 'Profile response is too large.');
    }
}
