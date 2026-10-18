<?php

declare(strict_types=1);

use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Http;
use Wtroiano\GuardedHttp\Services\GuardedHttp;
use Wtroiano\GuardedHttp\Tests\Fixtures\FixedEndpointPolicy;
use Wtroiano\GuardedHttp\Tests\Fixtures\LoopbackHttpServer;

it('uses the newly approved IP when reusing a pending request', function (): void {
    $server = new LoopbackHttpServer;
    Http::preventStrayRequests();
    Http::allowStrayRequests([$server->url('/*')]);

    try {
        $request = resolve(GuardedHttp::class)->request($server->policy(['127.0.0.1', '127.0.0.2']), 1024, timeout: 5);

        expect($request->get($server->url('/first'))->body())->toBe('server-a');
        expect($request->get($server->url('/second'))->body())->toBe('server-b');
    } finally {
        $server->stop();
    }
});

it('aborts an oversized response through the real cURL transport', function (string $path): void {
    $server = new LoopbackHttpServer;
    Http::preventStrayRequests();
    Http::allowStrayRequests([$server->url('/*')]);
    $stats = null;

    try {
        $request = resolve(GuardedHttp::class)->request($server->policy(), 1024, timeout: 5, limitMessage: 'Transfer too large.')
            ->withOptions(['on_stats' => function (TransferStats $transfer) use (&$stats): void {
                $stats = $transfer;
            }]);

        expect(fn () => $request->get($server->url($path)))->toThrow(RuntimeException::class, 'Transfer too large.');
        expect($stats)->toBeInstanceOf(TransferStats::class);
        expect($stats->getHandlerErrorData())->toBe(CURLE_WRITE_ERROR);
        expect($stats->getHandlerStat('size_download'))->toBeLessThan(8 * 1024 * 1024);
        expect($request->get($server->url('/exact'))->body())->toBe('é12');
    } finally {
        $server->stop();
    }
})->with(['/chunked', '/gzip']);

it('returns a redirect without sending a second real request', function (): void {
    $server = new LoopbackHttpServer;
    Http::preventStrayRequests();
    Http::allowStrayRequests([$server->url('/redirect')]);

    try {
        $response = resolve(GuardedHttp::class)->request($server->policy(), 1024, timeout: 5)->get($server->url('/redirect'));

        expect($response->status())->toBe(302);
        expect($response->header('Location'))->toBe('/destination');
    } finally {
        $server->stop();
    }
});

it('rejects redirect-enabling options before dispatch', function (bool|array $redirects): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('', 302, ['Location' => 'https://other.test/data'])]);
    $request = resolve(GuardedHttp::class)->request(new FixedEndpointPolicy, 1024)
        ->withOptions(['allow_redirects' => $redirects]);

    expect(fn () => $request->get('https://service.test/data'))->toThrow(InvalidArgumentException::class, 'Redirects are not supported by Guarded HTTP.');
    Http::assertNothingSent();
})->with([true, [['max' => 1]]]);
