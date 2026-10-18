<?php

declare(strict_types=1);

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Contracts\RequestProfile;
use Wtroiano\GuardedHttp\Pipelines\RequestPipeline;
use Wtroiano\GuardedHttp\Pipelines\RequestProfilePipeline;
use Wtroiano\GuardedHttp\Services\GuardedHttp;
use Wtroiano\GuardedHttp\Support\RequestSettings;
use Wtroiano\GuardedHttp\Tests\Fixtures\FixedEndpointPolicy;
use Wtroiano\GuardedHttp\Tests\Fixtures\FixedRequestProfile;

it('applies the same settings and transport protections for configured and class profiles', function (string|RequestProfile $profile): void {
    app()->instance(EndpointPolicy::class, new FixedEndpointPolicy('203.0.113.7'));
    config()->set('guarded-http.profiles', [
        'configured' => [
            'policy' => EndpointPolicy::class,
            'maximum_bytes' => 4,
            'timeout_seconds' => 2.5,
            'connect_timeout_seconds' => 3,
            'limit_message' => 'Profile response is too large.',
        ],
        'class-profile' => FixedRequestProfile::class,
    ]);
    $seen = null;
    $request = resolve(GuardedHttp::class)->profile($profile)
        ->withOptions(['proxy' => 'http://untrusted.test', 'stream' => true])
        ->setHandler(function (RequestInterface $request, array $options) use (&$seen): FulfilledPromise {
            $seen = $options;
            $options['sink']->write('safe');

            return new FulfilledPromise(new Response(200, [], $options['sink']));
        });

    expect($request->get('https://service.test/data')->body())->toBe('safe');
    expect($seen)->toMatchArray(['timeout' => 2.5, 'connect_timeout' => 2.5, 'allow_redirects' => false, 'proxy' => '', 'stream' => false]);
    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['service.test:443:203.0.113.7']);

    $request->setHandler(function (RequestInterface $request, array $options): FulfilledPromise {
        $options['sink']->write('12345');

        return new FulfilledPromise(new Response(200, [], $options['sink']));
    });

    expect(fn () => $request->get('https://service.test/data'))->toThrow(RuntimeException::class, 'Profile response is too large.');
})->with([
    'configuration array' => ['configured'],
    'configured class' => ['class-profile'],
    'class name' => [FixedRequestProfile::class],
    'profile instance' => [new FixedRequestProfile(new FixedEndpointPolicy('203.0.113.7'))],
]);

it('composes the pipelines directly with default settings and preserves HTTP fakes and caller headers', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('safe')]);
    config()->set('guarded-http.profiles.default', ['policy' => FixedEndpointPolicy::class, 'maximum_bytes' => 4]);

    $request = 'default'
        |> resolve(RequestProfilePipeline::class)
        |> resolve(RequestPipeline::class);
    $request->withToken('test-token');

    expect($request->getOptions())->toMatchArray(['timeout' => 30.0, 'connect_timeout' => 5.0]);
    expect($request->get('https://service.test/data')->body())->toBe('safe');
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('reads updated configuration and validates destinations for every request', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('safe')]);
    $policy = new FixedEndpointPolicy;
    app()->instance(EndpointPolicy::class, $policy);
    config()->set('guarded-http.profiles.current', ['policy' => EndpointPolicy::class, 'maximum_bytes' => 4]);
    $transport = resolve(GuardedHttp::class);

    expect($transport->profile('current')->get('https://service.test/data')->body())->toBe('safe');
    config()->set('guarded-http.profiles.current.maximum_bytes', 3);

    expect(fn () => $transport->profile('current')->get('https://service.test/data'))->toThrow(RuntimeException::class, 'byte limit');
    expect($policy->requests)->toBe(['https://service.test/data', 'https://service.test/data']);
    Http::assertSentCount(2);
});

it('rejects invalid configured settings before dispatch', function (array $changes): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('safe')]);
    config()->set('guarded-http.profiles.invalid', array_replace([
        'policy' => FixedEndpointPolicy::class,
        'maximum_bytes' => 4,
    ], $changes));

    expect(fn () => resolve(GuardedHttp::class)->profile('invalid')->get('https://service.test/data'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'unknown option' => [['allow_redirects' => true]],
    'missing maximum' => [['maximum_bytes' => null]],
    'string maximum' => [['maximum_bytes' => '4']],
    'nonpositive maximum' => [['maximum_bytes' => 0]],
    'null timeout' => [['timeout_seconds' => null]],
    'string timeout' => [['timeout_seconds' => '3']],
    'nonfinite timeout' => [['timeout_seconds' => INF]],
    'nonpositive timeout' => [['timeout_seconds' => 0]],
    'null connection timeout' => [['connect_timeout_seconds' => null]],
    'nonfinite connection timeout' => [['connect_timeout_seconds' => NAN]],
    'nonpositive connection timeout' => [['connect_timeout_seconds' => -1]],
    'invalid message' => [['limit_message' => null]],
    'missing policy' => [['policy' => null]],
    'unknown policy' => [['policy' => 'MissingEndpointPolicy']],
    'wrong policy class' => [['policy' => stdClass::class]],
    'policy object in configuration' => [['policy' => new FixedEndpointPolicy]],
]);

it('rejects malformed profile definitions', function (mixed $definition): void {
    config()->set('guarded-http.profiles.invalid', $definition);

    expect(fn () => resolve(GuardedHttp::class)->profile('invalid'))->toThrow(InvalidArgumentException::class);
})->with([
    'null' => [null],
    'boolean' => [false],
    'wrong class' => [stdClass::class],
    'unregistered alias' => ['other-profile'],
]);

it('rejects unknown profile names', function (): void {
    expect(fn () => resolve(GuardedHttp::class)->profile('missing'))->toThrow(InvalidArgumentException::class, 'Unknown Guarded HTTP profile');
});

it('rejects a malformed profiles configuration', function (): void {
    config()->set('guarded-http.profiles', false);

    expect(fn () => resolve(GuardedHttp::class)->profile('invalid'))->toThrow(InvalidArgumentException::class, 'profiles must be an array');
});

it('rejects container bindings that return an incompatible profile', function (): void {
    app()->bind(FixedRequestProfile::class, fn (): stdClass => new stdClass);

    expect(fn () => resolve(GuardedHttp::class)->profile(FixedRequestProfile::class))->toThrow(InvalidArgumentException::class, 'must implement RequestProfile');
});

it('rejects container bindings that return an incompatible policy', function (): void {
    app()->bind(EndpointPolicy::class, fn (): stdClass => new stdClass);
    config()->set('guarded-http.profiles.invalid', ['policy' => EndpointPolicy::class, 'maximum_bytes' => 4]);

    expect(fn () => resolve(GuardedHttp::class)->profile('invalid'))->toThrow(InvalidArgumentException::class, 'must implement EndpointPolicy');
});

it('validates limits returned by class profiles before dispatch', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://service.test/data' => Http::response('safe')]);
    $profile = new class implements RequestProfile
    {
        public function settings(): RequestSettings
        {
            return new RequestSettings(new FixedEndpointPolicy, maximum: 4, timeout: INF);
        }
    };

    expect(fn () => resolve(GuardedHttp::class)->profile($profile)->get('https://service.test/data'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});
