<?php

namespace App\Providers;

use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\IncusThreadSandboxManager;
use App\Services\Threads\SessionSyncAdapter;
use Illuminate\Support\ServiceProvider;

class ThreadSandboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SandboxManager::class, IncusThreadSandboxManager::class);
        $this->app->singleton(SessionFileSync::class, SessionSyncAdapter::class);
    }
}
