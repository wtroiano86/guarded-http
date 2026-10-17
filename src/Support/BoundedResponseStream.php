<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $written = 0;

    public function __construct(StreamInterface $stream, private readonly int $maximum, private readonly string $limitMessage = 'HTTP response exceeds the byte limit.')
    {
        if ($maximum < 1) {
            throw new InvalidArgumentException('The response byte limit must be positive.');
        }

        $this->stream = $stream;
    }

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
