<?php

use App\Models\User;
use App\Providers\E2eServiceProvider;
use App\Services\E2e\E2eMode;
use App\Services\RepositoryConfig;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\RedisBrokerStreams;
use Illuminate\Support\Facades\Artisan;

it('is off in the testing environment without the flag', function (): void {
    expect(E2eMode::isActive())->toBeFalse();

    $this->get('/letmein')->assertNotFound();
    $this->assertGuest();
});

it('is on in the testing environment with the flag', function (): void {
    config(['yak.e2e.enabled' => true]);
    $user = User::factory()->create();

    expect(E2eMode::isActive())->toBeTrue();

    $this->get('/letmein')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

it('is never on outside testing', function (string $environment): void {
    $this->app['env'] = $environment;
    config(['yak.e2e.enabled' => true]);

    expect(E2eMode::isActive())->toBeFalse();

    if ($environment !== 'local') {
        $this->get('/letmein')->assertNotFound();
        $this->assertGuest();
    }
})->with(['local', 'staging', 'production']);

it('registers no e2e command without the mode', function (): void {
    expect(array_keys(Artisan::all()))->not->toContain('yak:e2e-seed')->not->toContain('yak:e2e-runner');

    $before = get_class(app(SandboxManager::class));
    (new E2eServiceProvider(app()))->register();

    expect(get_class(app(SandboxManager::class)))->toBe($before);
});

it('refuses to run a registered command once the mode is off', function (): void {
    config(['yak.e2e.enabled' => true]);
    (new E2eServiceProvider(app()))->boot();
    config(['yak.e2e.enabled' => false]);

    $this->artisan('yak:e2e-seed')->expectsOutputToContain('only runs in the e2e test environment')->assertExitCode(1);
    $this->artisan('yak:e2e-runner', ['--once' => true])->expectsOutputToContain('only runs in the e2e test environment')->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

it('runs without the feature suite fakes', function (): void {
    expect(app(SandboxManager::class))->not->toBeInstanceOf(FakeSandboxManager::class)
        ->and(app(BrokerStreams::class))->toBeInstanceOf(RedisBrokerStreams::class)
        ->and(get_class(app(RepositoryConfig::class)))->toBe(RepositoryConfig::class);
});
