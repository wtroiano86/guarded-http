<?php

declare(strict_types=1);

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Services\GuardedHttp;
use Wtroiano\GuardedHttp\Tests\Fixtures\FixedEndpointPolicy;

it('pins each validated destination and enforces transport options before dispatch', function (string $url, string $address, array $pins): void {
    $policy = new FixedEndpointPolicy($address);
    $seen = null;
    $request = app(GuardedHttp::class)->request($policy, 4, timeout: 8, connectTimeout: 2)
        ->withOptions(['allow_redirects' => true, 'proxy' => 'http://untrusted.test', 'stream' => true])
        ->setHandler(function (RequestInterface $request, array $options) use (&$seen): FulfilledPromise {
            $seen = $options;
            $options['sink']->write('safe');
            $options['sink']->rewind();

            return new FulfilledPromise(new Response(200, [], $options['sink']));
        });

    expect($request->get($url)->body())->toBe('safe');
    expect($policy->requests)->toBe([$url]);
    expect($seen)->toMatchArray(['allow_redirects' => false, 'proxy' => '', 'stream' => false, 'timeout' => 8.0, 'connect_timeout' => 2.0]);
    expect($seen['curl'][CURLOPT_RESOLVE])->toBe($pins);
    expect($seen['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTP | CURLPROTO_HTTPS);
})->with([
    'hostname' => ['https://service.test/data', '93.184.216.34', ['service.test:443:93.184.216.34']],
    'IPv4' => ['https://93.184.216.34/data', '93.184.216.34', ['93.184.216.34:443:93.184.216.34']],
    'IPv6 hostname' => ['https://service.test/data', '2606:4700:4700::1111', ['service.test:443:[2606:4700:4700::1111]']],
    'IPv6 literal' => ['https://[2606:4700:4700::1111]/data', '2606:4700:4700::1111', []],
]);

it('rejects a denied endpoint before sending or retrying a write', function (): void {
    $sent = 0;
    $policy = new class implements EndpointPolicy
    {
        public function resolve(string $url): array
        {
            throw new RuntimeException('Destination denied.');
        }
    };
    $request = app(GuardedHttp::class)->request($policy, 4)->setHandler(function () use (&$sent): FulfilledPromise {
        $sent++;

        return new FulfilledPromise(new Response(200));
    });

    expect(fn () => $request->post('https://service.test/effect'))->toThrow(RuntimeException::class, 'Destination denied.');
    expect($sent)->toBe(0);
});

it('does not repeat a write after a transport timeout', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/effect' => Http::failedConnection()]);
    $policy = new FixedEndpointPolicy;

    expect(fn () => app(GuardedHttp::class)->request($policy, 4)->post('https://service.test/effect'))->toThrow(ConnectionException::class);
    expect($policy->requests)->toBe(['https://service.test/effect']);
});

it('rejects oversized declared responses before downloading the body', function (): void {
    $request = app(GuardedHttp::class)->request(new FixedEndpointPolicy, 4, limitMessage: 'Too large.')
        ->setHandler(function (RequestInterface $request, array $options): FulfilledPromise {
            $response = new Response(200, ['Content-Length' => '5']);
            $options['on_headers']($response);

            return new FulfilledPromise($response);
        });

    expect(fn () => $request->get('https://service.test/data'))->toThrow(RuntimeException::class, 'Too large.');
});

it('returns redirects without following them', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('', 302, ['Location' => 'https://other.test/data'])]);

    expect(app(GuardedHttp::class)->request(new FixedEndpointPolicy, 4)->get('https://service.test/data')->status())->toBe(302);
    Http::assertSentCount(1);
});

it('bounds bytes during transport writes without a declared content length', function (): void {
    $request = app(GuardedHttp::class)->request(new FixedEndpointPolicy, 4)
        ->setHandler(function (RequestInterface $request, array $options): FulfilledPromise {
            $options['sink']->write('123');
            $options['sink']->write('45');

            return new FulfilledPromise(new Response(200));
        });

    expect(fn () => $request->get('https://service.test/data'))->toThrow(RuntimeException::class, 'byte limit');
});

it('bounds responses from alternate handlers that bypass the transport sink', function (): void {
    $request = app(GuardedHttp::class)->request(new FixedEndpointPolicy, 4)
        ->setHandler(fn (): FulfilledPromise => new FulfilledPromise(new Response(200, [], '12345')));

    expect(fn () => $request->get('https://service.test/data'))->toThrow(RuntimeException::class, 'byte limit');
});

it('reads complete responses when a stream returns short chunks', function (): void {
    $body = new class(Utils::streamFor('1234')) implements StreamInterface
    {
        use StreamDecoratorTrait;

        private StreamInterface $stream;

        public function read(int $length): string
        {
            return $this->stream->read(min(1, $length));
        }
    };
    $request = app(GuardedHttp::class)->request(new FixedEndpointPolicy, 4)
        ->setHandler(fn (): FulfilledPromise => new FulfilledPromise(new Response(200, [], $body)));

    expect($request->get('https://service.test/data')->body())->toBe('1234');
});

it('preserves Laravel HTTP fakes and caller headers with any positive sufficient byte budget', function (int $maximum): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('safe')]);

    expect(app(GuardedHttp::class)->request(new FixedEndpointPolicy, $maximum)->withToken('test-token')->get('https://service.test/data')->body())->toBe('safe');
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
})->with([4, PHP_INT_MAX]);

it('rejects invalid limits before creating a request', function (int $maximum, float $timeout, float $connectTimeout): void {
    expect(fn () => app(GuardedHttp::class)->request(new FixedEndpointPolicy, $maximum, $timeout, $connectTimeout))->toThrow(InvalidArgumentException::class);
})->with([[0, 1, 1], [1, 0, 1], [1, 1, 0], [1, INF, 1], [1, 1, NAN]]);
