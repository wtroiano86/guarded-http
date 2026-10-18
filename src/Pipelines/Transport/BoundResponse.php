<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Pipelines\Transport;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Wtroiano\GuardedHttp\Support\AbortOnWriteFailureStream;
use Wtroiano\GuardedHttp\Support\BoundedResponseStream;

/**
 * Bounds declared response lengths, transport writes, and returned body reads.
 *
 * Runs after destination validation. A final read also checks alternate handlers
 * that ignore the sink. The temporary sink spills after 1 MiB, but the later
 * string materialization means this threshold is not a peak-memory limit.
 */
final readonly class BoundResponse
{
    /**
     * Accept a positive byte budget already validated by RequestPipeline.
     */
    public function __construct(private int $maximum, private string $limitMessage) {}

    /**
     * Wrap a handler with a fresh bounded sink and response-header checks.
     *
     * The returned handler raises RuntimeException if buffering fails or the
     * next handler returns no promise. Write failures abort cURL through a short
     * write, then propagate the original exception when the promise settles.
     * Header callback failures may be wrapped in transport errors.
     *
     * @param  callable(RequestInterface, array<string, mixed>): mixed  $handler
     * @return Closure(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $stream = fopen('php://temp/maxmemory:1048576', 'w+');
            throw_if($stream === false, RuntimeException::class, 'Unable to create the HTTP response buffer.');

            $sink = new AbortOnWriteFailureStream(new BoundedResponseStream(Utils::streamFor($stream), $this->maximum, $this->limitMessage));
            $options['sink'] = $sink;
            $options['on_headers'] = function (ResponseInterface $response): void {
                throw_if((int) $response->getHeaderLine('Content-Length') > $this->maximum, RuntimeException::class, $this->limitMessage);
            };

            $promise = $handler($request, $options);
            throw_unless($promise instanceof PromiseInterface, RuntimeException::class, 'Invalid HTTP handler.');

            return $promise->then(
                function (ResponseInterface $response) use ($sink): ResponseInterface {
                    $sink->throwIfFailed();

                    return $this->readResponse($response);
                },
                function (mixed $reason) use ($sink): PromiseInterface {
                    $sink->throwIfFailed();

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * Rewind seekable bodies and return a readable copy within the byte budget.
     *
     * Reading one byte beyond the remaining allowance detects overflow without
     * consuming an unbounded alternate-handler body. Close an oversized body
     * before failing; underlying read errors otherwise propagate.
     *
     * @throws RuntimeException When the returned body exceeds the byte budget.
     */
    private function readResponse(ResponseInterface $response): ResponseInterface
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $bytes = '';
        while (! $body->eof()) {
            $chunk = $body->read(min(8191, $this->maximum - mb_strlen($bytes, '8bit')) + 1);
            $bytes .= $chunk;
            if (mb_strlen($bytes, '8bit') > $this->maximum) {
                $body->close();
                throw new RuntimeException($this->limitMessage);
            }

            if ($chunk === '') {
                break;
            }
        }

        return $response->withBody(Utils::streamFor($bytes));
    }
}
