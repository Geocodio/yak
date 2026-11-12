<?php

namespace App\Providers;

use App\Services\Threads\Repos\MultiRepoToolRouter;
use App\Services\Threads\YakToolRouter;
use Illuminate\Support\ServiceProvider;

class MultiRepoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(YakToolRouter::class, fn (YakToolRouter $inner) => app()->make(MultiRepoToolRouter::class, ['inner' => $inner]));
    }
}
