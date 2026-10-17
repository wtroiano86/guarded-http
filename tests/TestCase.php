<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Wtroiano\GuardedHttp\GuardedHttpServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders(mixed $app): array
    {
        return [GuardedHttpServiceProvider::class];
    }
}
