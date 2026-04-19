<?php

namespace App\Providers;

use App\Agents\SandboxedAgentRunner;
use App\Contracts\AgentRunner;
use App\Listeners\RecordAiUsage;
use App\Services\IncusSandboxManager;
use App\Services\VideoRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Events\AgentPrompted;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IncusSandboxManager::class);

        $this->app->bind(AgentRunner::class, fn () => new SandboxedAgentRunner(app(IncusSandboxManager::class)));

        // VideoRenderer's constructor takes a primitive `string $videoDir`
        // which Laravel's container can't auto-resolve; without a binding,
        // RenderVideoJob fails to instantiate and every walkthrough ships
        // as the raw browser webm with no Remotion composite.
        $this->app->bind(VideoRenderer::class, fn () => new VideoRenderer(videoDir: base_path('video')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(AgentPrompted::class, RecordAiUsage::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
