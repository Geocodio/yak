<?php

namespace App\Providers;

use App\Services\Stacks\StackToolRouter;
use App\Services\Threads\YakToolRouter;
use Illuminate\Support\ServiceProvider;

class StackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(YakToolRouter::class, fn (YakToolRouter $inner) => app()->make(StackToolRouter::class, ['inner' => $inner]));
    }
}
