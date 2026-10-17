<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Tests\Fixtures;

use Wtroiano\GuardedHttp\EndpointPolicy;

final class FixedEndpointPolicy implements EndpointPolicy
{
    /** @var list<string> */
    public array $requests = [];

    public function __construct(private readonly string $address = '93.184.216.34') {}

    public function resolve(string $url): array
    {
        $this->requests[] = $url;

        return ['host' => mb_trim((string) parse_url($url, PHP_URL_HOST), '[]'), 'port' => 443, 'address' => $this->address];
    }
}
