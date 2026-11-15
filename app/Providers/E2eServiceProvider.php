<?php

namespace App\Providers;

use App\Services\E2e\E2eMode;
use App\Services\E2e\E2eRunnerCommand;
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

        $this->bindFakes();
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
    }

    /**
     * The e2e bindings, public so the pipeline test applies exactly the same ones.
     */
    public function bindFakes(): void {}
}
