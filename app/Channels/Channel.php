<?php

namespace App\Channels;

use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver;
use App\Channels\Contracts\NotificationDriver;
use App\Services\HealthCheck\HealthCheck;
use Illuminate\Routing\Router;

interface Channel
{
    /** Stable key — matches YakTask::$source and the config('yak.channels.{name}') key. */
    public function name(): string;

    /** @return array<int, string> */
    public function requiredConfig(): array;

    /** @return array<string, mixed> */
    public function config(): array;

    public function enabled(): bool;

    /** No-op if the channel has no webhook routes. */
    public function registerRoutes(Router $router): void;

    public function inputDriver(): ?InputDriver;

    public function notificationDriver(): ?NotificationDriver;

    public function ciDriver(): ?CIDriver;

    public function ciBuildScanner(): ?CIBuildScanner;

    /** @return array<int, HealthCheck> */
    public function healthChecks(): array;
}
