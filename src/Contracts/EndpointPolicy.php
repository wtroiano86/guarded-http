<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Contracts;

/**
 * Defines the application's trust boundary for outbound HTTP destinations.
 *
 * The transport trusts the returned destination. Implementations own URL
 * validation, allowed schemes and ports, DNS resolution, and address rules.
 */
interface EndpointPolicy
{
    /**
     * Authorize the URL and select an address from its validated destinations.
     *
     * Validate every resolved address before selecting one. Return the URL's
     * normalized host and port, with an IP address to pin; IPv6 values omit
     * brackets. A literal IP host must match the returned address.
     *
     * Called for each outgoing request. Throw on denial or resolution failure;
     * the transport propagates the exception without dispatching that request.
     *
     * @return array{host: string, port: int, address: string}
     */
    public function resolve(string $url): array;
}
