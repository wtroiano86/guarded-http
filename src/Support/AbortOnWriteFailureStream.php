<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Converts write failures into short writes that abort cURL immediately.
 *
 * PHP can defer exceptions thrown inside a cURL callback until the transfer
 * finishes. Retain the original failure and rethrow it after the handler settles.
 * The standalone BoundedResponseStream keeps its immediate-throw behavior.
 *
 * @internal
 */
final class AbortOnWriteFailureStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private ?Throwable $failure = null;

    /** Wrap the bounded sink without changing its standalone exception contract. */
    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Return zero after a failed write, including all subsequent attempts.
     *
     * A short write tells cURL to stop with CURLE_WRITE_ERROR. Retaining the
     * first exception prevents a later write from masking the original cause.
     */
    public function write(string $string): int
    {
        if ($this->failure instanceof Throwable) {
            return 0;
        }

        try {
            return $this->stream->write($string);
        } catch (Throwable $throwable) {
            $this->failure = $throwable;

            return 0;
        }
    }

    /** Surface the original write failure once execution has left cURL. */
    public function throwIfFailed(): void
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
