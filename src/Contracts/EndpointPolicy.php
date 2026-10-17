<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Contracts;

interface EndpointPolicy
{
    /** @return array{host: string, port: int, address: string} */
    public function resolve(string $url): array;
}
