<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Providers;

use Illuminate\Support\ServiceProvider;
use Wtroiano\GuardedHttp\Services\GuardedHttp;

/**
 * Integrates the compatibility entry point with Laravel's service container.
 *
 * Consumers supply their own endpoint policies and optional profile configuration.
 */
final class GuardedHttpServiceProvider extends ServiceProvider
{
    /**
     * Register GuardedHttp as a singleton while preserving an existing binding.
     */
    public function register(): void
    {
        $this->app->singletonIf(GuardedHttp::class);
    }
}
