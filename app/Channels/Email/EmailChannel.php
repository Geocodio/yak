<?php

namespace App\Channels\Email;

use App\Channels\Channel;
use App\Channels\Concerns\ChecksRequiredConfig;
use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver as InputDriverContract;
use App\Channels\Contracts\NotificationDriver;
use App\Services\HealthCheck\HealthCheck as HealthCheckContract;
use Illuminate\Routing\Router;

/**
 * One-way entry point: a team member forwards an email to Yak's inbound
 * address and Yak creates a task from it. Nothing about the task flows
 * back over email except the single acknowledgment.
 */
final class EmailChannel implements Channel
{
    use ChecksRequiredConfig;

    public function name(): string
    {
        return 'email';
    }

    /**
     * @return array<int, string>
     */
    public function requiredConfig(): array
    {
        return ['webhook_password', 'inbound_address'];
    }

    public function registerRoutes(Router $router): void
    {
        $router->post('email', WebhookController::class)->name('webhooks.email');
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
