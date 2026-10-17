<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wtroiano\GuardedHttp\GuardedHttp;
use Wtroiano\GuardedHttp\GuardedHttpServiceProvider;
use Wtroiano\GuardedHttp\Tests\Fixtures\FixedEndpointPolicy;

it('resolves the package transport as a singleton and sends requests through Laravel fakes', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('safe')]);

    expect(app()->bound(GuardedHttp::class))->toBeTrue();
    $transport = app(GuardedHttp::class);
    expect($transport)->toBe(app(GuardedHttp::class));
    expect($transport->request(new FixedEndpointPolicy, 4)->get('https://service.test/data')->body())->toBe('safe');
    Http::assertSentCount(1);
});

it('preserves a transport supplied by the host application', function (): void {
    $transport = new GuardedHttp;
    app()->instance(GuardedHttp::class, $transport);

    app()->register(GuardedHttpServiceProvider::class, force: true);

    expect(app(GuardedHttp::class))->toBe($transport);
});
