<?php

use App\Models\Thread;
use App\Providers\ThreadSandboxServiceProvider;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\IncusThreadSandboxManager;
use App\Services\Threads\SessionSyncAdapter;
use Illuminate\Container\Container;
use Tests\Support\FakeSessionFileSync;

test('the provider binds the Incus manager and the session adapter', function () {
    $container = new Container;
    (new ThreadSandboxServiceProvider($container))->register();

    expect($container->make(SandboxManager::class))->toBeInstanceOf(IncusThreadSandboxManager::class)
        ->and($container->make(SessionFileSync::class))->toBeInstanceOf(SessionSyncAdapter::class);
});

test('feature tests resolve the fakes', function () {
    expect(app(SandboxManager::class))->toBeInstanceOf(FakeSandboxManager::class)
        ->and(app(SessionFileSync::class))->toBeInstanceOf(FakeSessionFileSync::class);
});

test('the adapter explains when the session store is not merged', function () {
    if (class_exists('App\\Services\\SessionStore\\SessionSync')) {
        $this->markTestSkipped('session store is merged');
    }

    expect(fn () => (new SessionSyncAdapter)->syncFromSandbox(Thread::factory()->create()))->toThrow(LogicException::class);
});
