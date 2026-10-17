<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp;

use Illuminate\Support\ServiceProvider;

final class GuardedHttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(GuardedHttp::class);
    }
}
