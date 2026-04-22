<?php

namespace App\Providers;

use App\Channels\ChannelRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ChannelServiceProvider extends ServiceProvider
{
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
        $registry = $this->app->make(ChannelRegistry::class);

        Route::prefix('webhooks')->group(function () use ($registry): void {
            foreach ($registry->enabled() as $channel) {
                $channel->registerRoutes(Route::getFacadeRoot());
            }
        });
    }
}
