<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Wtroiano\GuardedHttp\Providers\GuardedHttpServiceProvider;
use Wtroiano\GuardedHttp\Services\GuardedHttp;
use Wtroiano\GuardedHttp\Tests\Fixtures\FixedEndpointPolicy;

it('resolves the package transport as a singleton and sends requests through Laravel fakes', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('safe')]);

    expect(app()->bound(GuardedHttp::class))->toBeTrue();
    $transport = resolve(GuardedHttp::class);
    expect($transport)->toBe(resolve(GuardedHttp::class));
    expect($transport->request(new FixedEndpointPolicy, 4)->get('https://service.test/data')->body())->toBe('safe');
    Http::assertSentCount(1);
});

it('preserves a transport supplied by the host application', function (): void {
    $transport = new GuardedHttp;
    app()->instance(GuardedHttp::class, $transport);

    app()->register(GuardedHttpServiceProvider::class, force: true);

    expect(resolve(GuardedHttp::class))->toBe($transport);
});
