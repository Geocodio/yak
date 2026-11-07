<?php

namespace App\Providers;

use App\Agents\SandboxedAgentRunner;
use App\Contracts\AgentRunner;
use App\Models\User;
use App\Services\AppDiskUsage;
use App\Services\IncusSandboxManager;
use App\Services\SandboxPoolUsage;
use App\Services\Telemetry\Contracts\TelemetrySink;
use App\Services\Telemetry\Sinks\DatabaseSink;
use App\Services\Telemetry\Sinks\NullSink;
use App\Services\Telemetry\Telemetry;
use App\Services\VideoRenderer;
use App\Services\VideoThumbnailer;
use Carbon\CarbonImmutable;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // spatie/laravel-prometheus (pulled in by laravel-queue-metrics) serves
        // an unauthenticated /prometheus route by default. Yak exports metrics
        // through laravel-telemetry instead, so the route stays off. This runs
        // in register() because the route is added while that package boots.
        config(['prometheus.enabled' => false]);

        $this->app->singleton(IncusSandboxManager::class);

        $this->app->bind(AgentRunner::class, fn () => new SandboxedAgentRunner(app(IncusSandboxManager::class)));

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

        app(AppDiskUsage::class)->registerGauges();
        app(SandboxPoolUsage::class)->registerGauges();
        $this->configureTelemetryUi();

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

    /**
     * Every signed-in user may read the System page's telemetry: sign-in
     * is already limited to the allowed Google Workspace domains. The
     * declared panels fill gaps in the built-in ones: CPU and memory
     * utilization over time, and the app disk and sandbox pool gauges that AppDiskUsage and
     * SandboxPoolUsage publish.
     */
    private function configureTelemetryUi(): void
    {
        Gate::define('viewTelemetryUi', fn (?User $user): bool => $user !== null);

        TelemetryUi::metricPanel(
            id: 'yak-cpu-utilization',
            title: 'CPU utilization',
            metric: 'system_cpu_utilization_ratio',
            page: 'system',
            unit: 'percent',
        );

        TelemetryUi::metricPanel(
            id: 'yak-memory-utilization',
            title: 'Memory utilization',
            metric: 'system_memory_utilization_ratio',
            page: 'system',
            unit: 'percent',
            where: ['system_memory_state' => 'used'],
        );

        TelemetryUi::metricPanel(
            id: 'yak-app-disk-used',
            title: 'App disk used',
            metric: 'yak_app_disk_used_bytes',
            page: 'system',
            unit: 'bytes',
        );

        TelemetryUi::metricPanel(
            id: 'yak-app-disk-total',
            title: 'App disk size',
            metric: 'yak_app_disk_total_bytes',
            page: 'system',
            unit: 'bytes',
        );

        TelemetryUi::metricPanel(
            id: 'yak-sandbox-pool-used',
            title: 'Sandbox pool used',
            metric: 'yak_sandbox_pool_used_bytes',
            page: 'system',
            unit: 'bytes',
        );

        TelemetryUi::metricPanel(
            id: 'yak-sandbox-pool-total',
            title: 'Sandbox pool size',
            metric: 'yak_sandbox_pool_total_bytes',
            page: 'system',
            unit: 'bytes',
        );
    }
}
