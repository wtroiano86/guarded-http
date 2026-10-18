<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;
use Wtroiano\GuardedHttp\Pipelines\RequestPipeline;
use Wtroiano\GuardedHttp\Pipelines\RequestProfilePipeline;
use Wtroiano\GuardedHttp\Providers\GuardedHttpServiceProvider;
use Wtroiano\GuardedHttp\Services\GuardedHttp;

/**
 * Smoke-test the distributed package using only the consumer's autoloader.
 *
 * This separate process proves discovery without manually registering the
 * provider or loading the package checkout's development dependencies.
 */
require $argv[1].'/vendor/autoload.php';

$app = Application::configure(basePath: $argv[1])->create();
$app->make(Kernel::class)->bootstrap();

if (! isset($app->getLoadedProviders()[GuardedHttpServiceProvider::class])) {
    throw new RuntimeException('Laravel did not discover the package provider.');
}

if (! $app->isShared(GuardedHttp::class) || $app->make(GuardedHttp::class) !== $app->make(GuardedHttp::class)) {
    throw new RuntimeException('The package transport was not registered as a singleton.');
}

$policy = new class implements EndpointPolicy
{
    public int $calls = 0;

    public function resolve(string $url): array
    {
        if ($url !== 'https://service.test/data') {
            throw new RuntimeException('Unexpected smoke-test destination.');
        }

        $this->calls++;

        return ['host' => 'service.test', 'port' => 443, 'address' => '203.0.113.7'];
    }
};

$app->instance(EndpointPolicy::class, $policy);
$app->make('config')->set('guarded-http.profiles.smoke', [
    'policy' => EndpointPolicy::class,
    'maximum_bytes' => 4,
]);

Http::preventStrayRequests();
Http::fake(['https://service.test/data' => Http::response('safe')]);

$request = 'smoke'
    |> $app->make(RequestProfilePipeline::class)->__invoke(...)
    |> $app->make(RequestPipeline::class)->__invoke(...);

if ($request->get('https://service.test/data')->body() !== 'safe' || $policy->calls !== 1 || Http::recorded()->count() !== 1) {
    throw new RuntimeException('The installed package failed to compose profiles, validate the destination, or preserve HTTP fakes.');
}

echo 'Distribution contents, Laravel discovery, profiles, and HTTP fakes passed.'.PHP_EOL;
