<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Wtroiano\GuardedHttp\BoundedResponseStream;

it('counts bytes across writes and prevents seeking from resetting the budget', function (): void {
    $stream = new BoundedResponseStream(Utils::streamFor(''), 4, 'Body too large.');
    expect($stream->write('é'))->toBe(2);
    expect($stream->write('12'))->toBe(2);
    $stream->rewind();
    expect($stream->getContents())->toBe('é12');
    $stream->rewind();
    expect(fn () => $stream->write('x'))->toThrow(RuntimeException::class, 'Body too large.');
});

it('rejects a nonpositive byte budget', function (): void {
    expect(fn () => new BoundedResponseStream(Utils::streamFor(''), 0))->toThrow(InvalidArgumentException::class);
});
