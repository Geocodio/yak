<?php

namespace App\Channels\Drone;

use App\Channels\Channel;
use App\Channels\Concerns\ChecksRequiredConfig;
use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver;
use App\Channels\Contracts\NotificationDriver;
use App\Services\HealthCheck\HealthCheck as HealthCheckContract;
use Illuminate\Routing\Router;

final class DroneChannel implements Channel
{
    use ChecksRequiredConfig;

    public function name(): string
    {
        return 'drone';
    }

    /**
     * @return array<int, string>
     */
    public function requiredConfig(): array
    {
        return ['url', 'token'];
    }

    public function registerRoutes(Router $router): void
    {
        // Drone has no inbound webhook — CI results are polled.
    }

    public function inputDriver(): ?InputDriver
    {
        return null;
    }

    public function notificationDriver(): ?NotificationDriver
    {
        return null;
    }

    public function ciDriver(): ?CIDriver
    {
        return null;
    }

    public function ciBuildScanner(): CIBuildScanner
    {
        return app(BuildScanner::class);
    }

    /**
     * @return array<int, HealthCheckContract>
     */
    public function healthChecks(): array
    {
        return [app(HealthCheck::class)];
    }
}
