<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Pipelines\Transport;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;

/**
 * Pins each outgoing request to the address selected by its endpoint policy.
 *
 * Runs before response buffering. The policy must validate the URL and every
 * resolved address; this stage enforces the transport options and trusts that
 * decision. Literal IPv6 hosts already identify their destination directly.
 */
final readonly class PinDestination
{
    public function __construct(private EndpointPolicy $policy) {}

    /**
     * Wrap the next handler with per-dispatch destination validation and pinning.
     *
     * The returned handler propagates policy failures before dispatch and raises
     * RuntimeException for malformed cURL options. Redirect-enabling options
     * fail before dispatch because Guzzle's outer redirect middleware has
     * already read them. Each dispatch opens a fresh connection so cURL cannot
     * reuse an earlier address after the policy selects a different one.
     *
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            throw_if(! empty($options['allow_redirects']), InvalidArgumentException::class, 'Redirects are not supported by Guarded HTTP.');

            $destination = $this->policy->resolve((string) $request->getUri());
            $address = str_contains($destination['address'], ':') ? '['.$destination['address'].']' : $destination['address'];
            $curl = $options['curl'] ?? [];
            throw_unless(is_array($curl), RuntimeException::class, 'Invalid HTTP options.');

            $curl[CURLOPT_RESOLVE] = str_contains($destination['host'], ':') ? [] : [$destination['host'].':'.$destination['port'].':'.$address];
            $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $curl[CURLOPT_FRESH_CONNECT] = true;
            $curl[CURLOPT_FORBID_REUSE] = true;
            $curl[CURLOPT_FOLLOWLOCATION] = false;
            $options['curl'] = $curl;
            $options['allow_redirects'] = false;
            $options['proxy'] = '';
            $options['stream'] = false;

            return $handler($request, $options);
        };
    }
}
