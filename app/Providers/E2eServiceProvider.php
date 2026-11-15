<?php

namespace App\Providers;

use App\Services\E2e\E2eMode;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the nightly journeys' fakes; inert outside the e2e test environment.
 */
class E2eServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! E2eMode::isActive()) {
            return;
        }

        $this->bindFakes();
    }

    public function boot(): void
    {
        if (! E2eMode::isActive()) {
            return;
        }
    }

    /**
     * The e2e bindings, public so the pipeline test applies exactly the same ones.
     */
    public function bindFakes(): void {}
}
