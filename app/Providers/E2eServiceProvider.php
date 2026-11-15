<?php

namespace App\Providers;

use App\Channels\GitHub\AppService;
use App\Services\E2e\E2eGitHub;
use App\Services\E2e\E2eGitHubAppService;
use App\Services\E2e\E2eMode;
use App\Services\E2e\E2eRepositoryConfig;
use App\Services\E2e\E2eRunnerCommand;
use App\Services\E2e\E2eSandboxManager;
use App\Services\E2e\E2eSessionFileSync;
use App\Services\RepositoryConfig;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the nightly journeys' fakes; inert outside the e2e test environment.
 */
class E2eServiceProvider extends ServiceProvider
{
    /**
     * Does nothing unless E2eMode::isActive(); then bindFakes().
     */
    public function register(): void
    {
        if (! E2eMode::isActive()) {
            return;
        }

        // Booting callbacks run after every provider has registered, so these bindings replace the production ones.
        $this->app->booting(fn () => $this->bindFakes());
    }

    /**
     * Does nothing unless E2eMode::isActive(); then registers yak:e2e-seed and yak:e2e-runner and calls E2eGitHub::fake().
     */
    public function boot(): void
    {
        if (! E2eMode::isActive()) {
            return;
        }

        $this->commands([E2eRunnerCommand::class]);
        E2eGitHub::fake();
    }

    /**
     * The e2e bindings, public so the pipeline test applies exactly the same ones.
     */
    public function bindFakes(): void
    {
        $this->app->singleton(SandboxManager::class, E2eSandboxManager::class);
        $this->app->singleton(SessionFileSync::class, E2eSessionFileSync::class);
        $this->app->singleton(RepositoryConfig::class, E2eRepositoryConfig::class);
        $this->app->singleton(AppService::class, E2eGitHubAppService::class);
    }
}
