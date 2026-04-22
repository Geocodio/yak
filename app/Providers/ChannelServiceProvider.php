<?php

namespace App\Providers;

use App\Channels\ChannelRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ChannelServiceProvider extends ServiceProvider
{
    /**
     * Legacy webhook controller map — shrinks to nothing as channels
     * migrate. Once empty, this constant and the legacy branch below
     * are deleted (Phase 6).
     *
     * @var array<string, class-string>
     */
    private const LEGACY_CONTROLLERS = [
    ];

    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class, function (Application $app): ChannelRegistry {
            $registry = new ChannelRegistry;

            /** @var array<int, class-string> $classes */
            $classes = (array) config('yak.channel_classes', []);

            foreach ($classes as $class) {
                $registry->register($app->make($class));
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        /** @var ChannelRegistry $registry */
        $registry = $this->app->make(ChannelRegistry::class);

        Route::prefix('webhooks')->group(function () use ($registry): void {
            // Legacy path — only for channels that have NOT migrated yet.
            $this->registerLegacyRoutes($registry);

            // Registry path — migrated channels register their own routes.
            foreach ($registry->enabled() as $channel) {
                $channel->registerRoutes(Route::getFacadeRoot());
            }
        });
    }

    private function registerLegacyRoutes(ChannelRegistry $registry): void
    {
        /** @var array<string, class-string> $controllers */
        $controllers = self::LEGACY_CONTROLLERS;

        foreach ($controllers as $name => $controller) {
            if ($registry->for($name) !== null) {
                continue; // migrated — skip legacy wiring
            }

            $channel = $registry->for($name);
            if ($channel !== null && $channel->enabled()) {
                Route::post($name, $controller)->name("webhooks.{$name}");
            }
        }
    }
}
