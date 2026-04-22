<?php

use App\Channels\Channel;
use App\Channels\ChannelRegistry;
use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Contracts\CIDriver;
use App\Channels\Contracts\InputDriver;
use App\Channels\Contracts\NotificationDriver;
use App\Services\HealthCheck\HealthCheck;
use Illuminate\Routing\Router;

function fakeChannel(string $name, bool $enabled = true): Channel
{
    return new class($name, $enabled) implements Channel
    {
        public function __construct(private string $channelName, private bool $enabled) {}

        public function name(): string
        {
            return $this->channelName;
        }

        public function requiredConfig(): array
        {
            return [];
        }

        public function config(): array
        {
            return [];
        }

        public function enabled(): bool
        {
            return $this->enabled;
        }

        public function registerRoutes(Router $router): void {}

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

        public function ciBuildScanner(): ?CIBuildScanner
        {
            return null;
        }

        /** @return array<int, HealthCheck> */
        public function healthChecks(): array
        {
            return [];
        }
    };
}

it('registers a channel and looks it up by name', function (): void {
    $registry = new ChannelRegistry;
    $slack = fakeChannel('slack');

    $registry->register($slack);

    expect($registry->for('slack'))->toBe($slack);
});

it('returns null for an unknown channel', function (): void {
    $registry = new ChannelRegistry;

    expect($registry->for('unknown'))->toBeNull();
});

it('returns all registered channels', function (): void {
    $registry = new ChannelRegistry;
    $registry->register(fakeChannel('slack'));
    $registry->register(fakeChannel('linear'));

    expect($registry->all())->toHaveCount(2)->toHaveKeys(['slack', 'linear']);
});

it('returns only enabled channels', function (): void {
    $registry = new ChannelRegistry;
    $registry->register(fakeChannel('slack', enabled: true));
    $registry->register(fakeChannel('linear', enabled: false));

    $enabled = $registry->enabled();

    expect($enabled)->toHaveCount(1)->toHaveKey('slack');
});

it('overwrites when registering the same name twice', function (): void {
    $registry = new ChannelRegistry;
    $first = fakeChannel('slack');
    $second = fakeChannel('slack');

    $registry->register($first);
    $registry->register($second);

    expect($registry->for('slack'))->toBe($second);
});
