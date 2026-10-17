<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Services;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Support\BoundedResponseStream;

final readonly class GuardedHttp
{
    public function request(EndpointPolicy $policy, int $maximum, float $timeout = 30, float $connectTimeout = 5, string $limitMessage = 'HTTP response exceeds the byte limit.'): PendingRequest
    {
        throw_if($maximum < 1 || ! is_finite($timeout) || $timeout <= 0 || ! is_finite($connectTimeout) || $connectTimeout <= 0, InvalidArgumentException::class, 'Response limits and HTTP timeouts must be positive and finite.');

        return Http::timeout($timeout)->connectTimeout(min($connectTimeout, $timeout))
            ->withOptions(['allow_redirects' => false, 'stream' => false, 'proxy' => ''])
            ->withMiddleware(
                /** @param array<string, mixed> $options */
                fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler, $policy, $maximum, $limitMessage): PromiseInterface {
                    $destination = $policy->resolve((string) $request->getUri());
                    $address = str_contains($destination['address'], ':') ? '['.$destination['address'].']' : $destination['address'];
                    $curl = $options['curl'] ?? [];
                    throw_unless(is_array($curl), RuntimeException::class, 'Invalid HTTP options.');

                    $curl[CURLOPT_RESOLVE] = str_contains($destination['host'], ':') ? [] : [$destination['host'].':'.$destination['port'].':'.$address];
                    $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                    $options['curl'] = $curl;
                    $options['allow_redirects'] = false;
                    $options['proxy'] = '';
                    $options['stream'] = false;
                    $stream = fopen('php://temp/maxmemory:1048576', 'w+');
                    throw_if($stream === false, RuntimeException::class, 'Unable to create the HTTP response buffer.');

                    $options['sink'] = new BoundedResponseStream(Utils::streamFor($stream), $maximum, $limitMessage);
                    $options['on_headers'] = static function (ResponseInterface $response) use ($maximum, $limitMessage): void {
                        throw_if((int) $response->getHeaderLine('Content-Length') > $maximum, RuntimeException::class, $limitMessage);
                    };

                    $promise = $handler($request, $options);
                    throw_unless($promise instanceof PromiseInterface, RuntimeException::class, 'Invalid HTTP handler.');

                    return $promise->then(static function (ResponseInterface $response) use ($maximum, $limitMessage): ResponseInterface {
                        $body = $response->getBody();
                        if ($body->isSeekable()) {
                            $body->rewind();
                        }

                        $bytes = '';
                        while (! $body->eof()) {
                            $chunk = $body->read(min(8191, $maximum - mb_strlen($bytes, '8bit')) + 1);
                            $bytes .= $chunk;
                            if (mb_strlen($bytes, '8bit') > $maximum) {
                                $body->close();
                                throw new RuntimeException($limitMessage);
                            }

                            if ($chunk === '') {
                                break;
                            }
                        }

                        return $response->withBody(Utils::streamFor($bytes));
                    });
                })->setHandler(new CurlHandler);
    }
}
