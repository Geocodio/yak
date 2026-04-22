<?php

namespace App\Channels\GitHub;

use App\Channels\Channel;
use App\Channels\Concerns\ChecksRequiredConfig;
use App\Channels\Contracts\CIBuildScanner as CIBuildScannerContract;
use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver;
use App\Channels\Contracts\NotificationDriver as NotificationDriverContract;
use App\Services\HealthCheck\HealthCheck as HealthCheckContract;
use Illuminate\Routing\Router;

final class GitHubChannel implements Channel
{
    use ChecksRequiredConfig;

    public function name(): string
    {
        return 'github';
    }

    /**
     * @return array<int, string>
     */
    public function requiredConfig(): array
    {
        return ['app_id', 'private_key', 'webhook_secret'];
    }

    public function registerRoutes(Router $router): void
    {
        $router->post('github', WebhookController::class)->name('webhooks.github');

        // Legacy CI-specific route — kept for existing GitHub App installations
        // whose webhook URL still points here. Both pull_request and check_suite
        // events are handled by WebhookController, so new installs should use
        // the canonical `/webhooks/github` URL.
        $router->post('ci/github', WebhookController::class)->name('webhooks.ci.github');
    }

    public function inputDriver(): ?InputDriver
    {
        return null;
    }

    public function notificationDriver(): NotificationDriverContract
    {
        return app(NotificationDriver::class);
    }

    public function ciDriver(): ?CIDriver
    {
        return null;
    }

    public function ciBuildScanner(): CIBuildScannerContract
    {
        return app(ActionsBuildScanner::class);
    }

    /**
     * @return array<int, HealthCheckContract>
     */
    public function healthChecks(): array
    {
        return [app(HealthCheck::class)];
    }
}
