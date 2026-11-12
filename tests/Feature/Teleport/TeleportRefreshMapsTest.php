<?php

use App\Enums\RepositoryRole;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Teleport\TeleportRefreshMaps;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->thread = Thread::factory()->paused()->create(['repo' => 'acme/widgets']);
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->extra = ThreadRepository::factory()->role(RepositoryRole::Changes)->create(['thread_id' => $this->thread->id, 'repo' => 'acme/api', 'sandbox_path' => '/extras/api']);
});

it('reads the refresh map of an extra from its own folder', function () {
    $this->sandboxes->queueExecResult(Process::result("refresh:\n  composer.lock: composer install\n"));

    expect(app(TeleportRefreshMaps::class)->forRepository($this->thread, $this->extra))->toBe([['glob' => 'composer.lock', 'command' => 'composer install']]);
    expect($this->sandboxes->calls[0]['arguments']['command'])->toBe(['cat', '/extras/api/.yak/config.yml']);
});

it('returns an empty list for an invalid map', function () {
    $this->sandboxes->queueExecResult(Process::result("refresh:\n  - not a map\n"));

    expect(app(TeleportRefreshMaps::class)->forRepository($this->thread, $this->extra))->toBe([]);
});
