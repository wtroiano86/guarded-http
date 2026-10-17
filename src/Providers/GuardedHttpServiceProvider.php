<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Providers;

use Illuminate\Support\ServiceProvider;
use Wtroiano\GuardedHttp\Services\GuardedHttp;

final class GuardedHttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(GuardedHttp::class);
    }
}
