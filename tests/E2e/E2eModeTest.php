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

it('runs with the environment the start script sets', function (): void {
    $exported = [];
    foreach (file(base_path('scripts/e2e/env.sh'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^export ([A-Z0-9_]+)=(.*)$/', $line, $matches)) {
            $exported[$matches[1]] = $matches[2];
        }
    }

    $phpunit = simplexml_load_file(base_path('phpunit.xml'));
    expect($phpunit)->toBeInstanceOf(SimpleXMLElement::class);
    $mirrored = 0;
    foreach ($phpunit->php->env as $env) {
        $name = (string) $env['name'];

        if ($name === 'BCRYPT_ROUNDS' || preg_match('/^(YAK|PULSE|TELESCOPE|NIGHTWATCH|TELEMETRY|QUEUE_METRICS)_/', $name)) {
            expect($exported)->toHaveKey($name);
            expect($exported[$name])->toBe((string) $env['value']);
            $mirrored++;
        }
    }

    expect($mirrored)->toBeGreaterThan(5);
    expect($exported['APP_ENV'])->toBe('testing');
    expect($exported['YAK_E2E'])->toBe('true');
    expect((int) $exported['YAK_THREADS_TURN_GRACE_SECONDS'])->toBeLessThanOrEqual(5);
});
