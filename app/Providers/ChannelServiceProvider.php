<?php

namespace App\Providers;

use App\Channel;
use App\Http\Controllers\Webhooks\DroneWebhookController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\Webhooks\LinearWebhookController;
use App\Http\Controllers\Webhooks\SentryWebhookController;
use App\Http\Controllers\Webhooks\SlackWebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ChannelServiceProvider extends ServiceProvider
{
    /**
     * Channel names mapped to their webhook controller classes.
     *
     * @var array<string, class-string>
     */
    private const CHANNEL_CONTROLLERS = [
        'slack' => SlackWebhookController::class,
        'linear' => LinearWebhookController::class,
        'sentry' => SentryWebhookController::class,
        'drone' => DroneWebhookController::class,
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->registerWebhookRoutes();
    }

    /**
     * Register webhook routes for enabled channels.
     * GitHub routes are always registered; other channels are conditional.
     */
    protected function registerWebhookRoutes(): void
    {
        Route::prefix('webhooks')->group(function (): void {
            // GitHub is always registered (required channel)
            Route::post('github', GitHubWebhookController::class)->name('webhooks.github');

            // Register optional channels only when enabled
            foreach (self::CHANNEL_CONTROLLERS as $channel => $controller) {
                if ((new Channel($channel))->enabled()) {
                    Route::post($channel, $controller)->name("webhooks.{$channel}");
                }
            }
        });
    }
}
