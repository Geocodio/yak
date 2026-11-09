<?php

namespace App\Providers;

use App\Agents\BrokeredAgentRunner;
use App\Contracts\AgentRunner;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\Contracts\TelemetrySink;
use App\Services\Telemetry\Sinks\DatabaseSink;
use App\Services\Telemetry\Sinks\NullSink;
use App\Services\Telemetry\Telemetry;
use App\Services\Threads\DeltaBatcher;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\VideoRenderer;
use App\Services\VideoThumbnailer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IncusSandboxManager::class);
        // The driver is long-lived: its publisher queue, delta buffers and open-block tracking live across entries.
        $this->app->singleton(ThreadEventPublisher::class);
        $this->app->singleton(DeltaBatcher::class);
        $this->app->singleton(ThreadDriver::class);

        $this->app->bind(AgentRunner::class, BrokeredAgentRunner::class);

        // VideoRenderer's constructor takes a primitive `string $videoDir`
        // which Laravel's container can't auto-resolve; without a binding,
        // RenderVideoJob fails to instantiate and every walkthrough ships
        // as the raw browser webm with no Remotion composite.
        $this->app->bind(VideoRenderer::class, fn () => new VideoRenderer(videoDir: base_path('video')));

        $this->app->bind(VideoThumbnailer::class, fn () => new VideoThumbnailer(
            overlayPath: base_path('video/fixtures/play-overlay.png'),
        ));

        // Telemetry is local-only and opt-out: with the flag off the null
        // sink is bound and every emitter short-circuits before it.
        $this->app->singleton(TelemetrySink::class, function (): TelemetrySink {
            return (bool) config('yak.telemetry.enabled', true) ? new DatabaseSink : new NullSink;
        });

        $this->app->singleton(Telemetry::class, fn () => new Telemetry(
            sink: app(TelemetrySink::class),
            enabled: (bool) config('yak.telemetry.enabled', true),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Flux previously registered this namespace so `<x-layouts::auth.simple>`
        // resolves to resources/views/layouts/auth/simple.blade.php.
        Blade::anonymousComponentNamespace('layouts', 'layouts');

        // Listeners in app/Listeners are auto-discovered. Registering
        // RecordAiUsage here as well made every AI SDK call write two
        // ai_usages rows, doubling API spend on the Costs page.
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
