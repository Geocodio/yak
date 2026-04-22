<?php

namespace App\Channels\Linear;

use App\Channels\Channel;
use App\Channels\Concerns\ChecksRequiredConfig;
use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver as InputDriverContract;
use App\Channels\Contracts\NotificationDriver as NotificationDriverContract;
use App\Services\HealthCheck\HealthCheck as HealthCheckContract;
use Illuminate\Routing\Router;

final class LinearChannel implements Channel
{
    use ChecksRequiredConfig;

    public function name(): string
    {
        return 'linear';
    }

    /**
     * @return array<int, string>
     */
    public function requiredConfig(): array
    {
        return ['webhook_secret'];
    }

    public function registerRoutes(Router $router): void
    {
        $router->post('linear', WebhookController::class)->name('webhooks.linear');
    }

    public function inputDriver(): InputDriverContract
    {
        return app(InputDriver::class);
    }

    public function notificationDriver(): NotificationDriverContract
    {
        return app(NotificationDriver::class);
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
