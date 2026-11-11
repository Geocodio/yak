<?php

namespace App\Providers;

use App\Services\Threads\RefreshToolRouter;
use App\Services\Threads\YakToolRouter;
use Illuminate\Support\ServiceProvider;

class RefreshServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(YakToolRouter::class, fn (YakToolRouter $inner) => app()->make(RefreshToolRouter::class, ['inner' => $inner]));
    }
}
