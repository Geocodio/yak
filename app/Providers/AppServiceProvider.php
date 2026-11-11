<?php

namespace App\Providers;

use App\Agents\BrokerAgentRunner;
use App\Contracts\AgentRunner;
use App\Models\Device;
use App\Models\User;
use App\Services\AppDiskUsage;
use App\Services\IncusSandboxManager;
use App\Services\SandboxPoolUsage;
use App\Services\Telemetry\Contracts\TelemetrySink;
use App\Services\Telemetry\Sinks\DatabaseSink;
use App\Services\Telemetry\Sinks\NullSink;
use App\Services\Telemetry\Telemetry;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\RedisBrokerStreams;
use App\Services\VideoRenderer;
use App\Services\VideoThumbnailer;
use Carbon\CarbonImmutable;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->singleton(BrokerStreams::class, RedisBrokerStreams::class);

        $this->app->bind(AgentRunner::class, BrokerAgentRunner::class);

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
        $this->configureCliRateLimiters();

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
     * Every signed-in user may read the System section's telemetry: sign-in
     * is already limited to the allowed Google Workspace domains. The app
     * disk and sandbox pool charts join the package's own System page.
     */
    private function configureTelemetryUi(): void
    {
        Gate::define('viewTelemetryUi', fn (?User $user): bool => $user !== null);

        TelemetryUi::metricPanel(
            id: 'yak-app-disk',
            title: 'App disk',
            metric: 'yak_app_disk_usage_bytes',
            page: 'system',
            unit: 'bytes',
            by: 'state',
            subtitle: 'Used and size of the disk that holds Yak storage',
        );

        TelemetryUi::metricPanel(
            id: 'yak-sandbox-pool',
            title: 'Sandbox pool',
            metric: 'yak_sandbox_pool_usage_bytes',
            page: 'system',
            unit: 'bytes',
            by: 'state',
            subtitle: 'Used and size of the Incus ZFS pool that holds the sandboxes',
        );
    }

    protected function configureCliRateLimiters(): void
    {
        RateLimiter::for('cli-device', function (Request $request): Limit {
            $device = $request->attributes->get('device');

            return Limit::perMinute(120)->by($device instanceof Device ? 'device:' . $device->id : 'ip:' . $request->ip());
        });

        RateLimiter::for('cli-device-code', fn (Request $request): Limit => Limit::perMinute(10)->by('ip:' . $request->ip()));
    }
}
