<?php

namespace App\Providers;

use App\Channel;
use App\Channels\ChannelRegistry;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\Webhooks\SlackInteractiveWebhookController;
use App\Http\Controllers\Webhooks\SlackWebhookController;
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
        'slack' => SlackWebhookController::class,
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
            // GitHub is always registered (required channel) until it migrates in Phase 5.
            Route::post('github', GitHubWebhookController::class)->name('webhooks.github');

            // Legacy path — only for channels that have NOT migrated yet.
            foreach (self::LEGACY_CONTROLLERS as $name => $controller) {
                if ($registry->for($name) !== null) {
                    continue; // migrated — skip legacy wiring
                }

                if ((new Channel($name))->enabled()) {
                    Route::post($name, $controller)->name("webhooks.{$name}");
                }
            }

            // Legacy Slack interactive — removed when Slack migrates (Phase 4).
            if ($registry->for('slack') === null && (new Channel('slack'))->enabled()) {
                Route::post('slack/interactive', SlackInteractiveWebhookController::class)
                    ->name('webhooks.slack.interactive');
            }

            // Registry path — migrated channels register their own routes.
            foreach ($registry->enabled() as $channel) {
                $channel->registerRoutes(Route::getFacadeRoot());
            }

            // Legacy CI-specific route — kept for existing GitHub App installations
            // whose webhook URL still points here. Removed in Phase 5 when GitHub
            // migrates and takes over route registration itself.
            Route::post('ci/github', GitHubWebhookController::class)->name('webhooks.ci.github');
        });
    }
}
