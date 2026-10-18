<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Enforces a cumulative byte budget on writes through a PSR-7 stream wrapper.
 *
 * Seeking or overwriting does not restore the budget. Existing contents and
 * reads are not bounded, so the transport also checks returned response bodies.
 */
final class BoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    /**
     * Wrap a stream with a positive write budget measured in bytes.
     *
     * @throws InvalidArgumentException When the byte budget is less than one.
     */
    public function __construct(StreamInterface $stream, private readonly int $maximum, private readonly string $limitMessage = 'HTTP response exceeds the byte limit.')
    {
        throw_if($maximum < 1, InvalidArgumentException::class, 'The response byte limit must be positive.');

        $this->stream = $stream;
    }

    /**
     * Reject a chunk before writing if it would exceed the remaining budget.
     *
     * Charge only the bytes actually written by the wrapped stream. Underlying
     * write failures propagate to the caller.
     *
     * @throws RuntimeException When the incoming chunk exceeds the budget.
     */
    public function write(string $string): int
    {
        if ($this->written + mb_strlen($string, '8bit') > $this->maximum) {
            throw new RuntimeException($this->limitMessage);
        }

        $written = $this->stream->write($string);
        $this->written += $written;

        return $written;
    }
}
