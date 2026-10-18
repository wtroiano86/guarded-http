<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Wtroiano\GuardedHttp\Support\AbortOnWriteFailureStream;

it('retains the first write failure and refuses all subsequent writes', function (): void {
    $writes = 0;
    $stream = new AbortOnWriteFailureStream(FnStream::decorate(Utils::streamFor(''), [
        'write' => function (string $bytes) use (&$writes): int {
            $writes++;

            throw new Error('Storage write failed.');
        },
    ]));

    expect($stream->write('first'))->toBe(0);
    expect($stream->write('second'))->toBe(0);
    expect($writes)->toBe(1);
    expect(fn () => $stream->throwIfFailed())->toThrow(Error::class, 'Storage write failed.');
});
