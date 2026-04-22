<?php

namespace App\Channels\Sentry;

use App\Channels\Channel;
use App\Channels\Concerns\ChecksRequiredConfig;
use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver as InputDriverContract;
use App\Channels\Contracts\NotificationDriver;
use App\Services\HealthCheck\HealthCheck as HealthCheckContract;
use Illuminate\Routing\Router;

final class SentryChannel implements Channel
{
    use ChecksRequiredConfig;

    public function name(): string
    {
        return 'sentry';
    }

    /**
     * @return array<int, string>
     */
    public function requiredConfig(): array
    {
        return ['auth_token', 'webhook_secret', 'org_slug'];
    }

    public function registerRoutes(Router $router): void
    {
        $router->post('sentry', WebhookController::class)->name('webhooks.sentry');
    }

    public function inputDriver(): InputDriverContract
    {
        return app(InputDriver::class);
    }

    public function notificationDriver(): ?NotificationDriver
    {
        return null;
    }

    public function ciDriver(): ?CIDriver
    {
        return null;
    }

    public function ciBuildScanner(): ?CIBuildScanner
    {
        return null;
    }

    /**
     * @return array<int, HealthCheckContract>
     */
    public function healthChecks(): array
    {
        return [app(HealthCheck::class)];
    }
}
