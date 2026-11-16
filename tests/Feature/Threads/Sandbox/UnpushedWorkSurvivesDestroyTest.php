<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSessionFileSync;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    Sleep::fake();
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->survivalTempDir = sys_get_temp_dir() . '/yak-survive-' . uniqid();
    mkdir($this->survivalTempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->survivalTempDir);
    app()->instance(SandboxManager::class, $this->git);
});

afterEach(fn () => File::deleteDirectory($this->survivalTempDir));

function staleStoppedThread(LocalGitSandboxManager $git): Thread
{
    file_put_contents("{$git->workspace}/README.md", "edited but never committed\n");
    file_put_contents("{$git->workspace}/untracked.txt", "brand new\n");

    return Thread::factory()->stopped()->create([
        'repo' => 'acme/app',
        'branch' => $git->branch,
        'runner_state' => RunnerState::None,
        'last_activity_at' => now()->subHours(73),
    ]);
}

test('uncommitted and untracked work survives the 72 hour destroy and the restore', function () {
    $thread = staleStoppedThread($this->git);

    $this->artisan('yak:thread-sandboxes')->assertSuccessful();

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($this->git->git(['show', "{$this->git->branch}:untracked.txt"]))->toBe('brand new')
        ->and($this->git->git(['show', "{$this->git->branch}:README.md"]))->toBe('edited but never committed');

    // The container is gone: the next one starts from a clean checkout of main.
    $this->git->git(['reset', '--hard'], $this->git->workspace);
    $this->git->git(['clean', '-fd'], $this->git->workspace);
    $this->git->git(['checkout', 'main'], $this->git->workspace);
    $this->git->git(['branch', '-D', $this->git->branch], $this->git->workspace);
    expect(file_exists("{$this->git->workspace}/untracked.txt"))->toBeFalse();

    expect(app(ThreadSandbox::class)->ensureAwake($thread->fresh())->ready)->toBeTrue()
        ->and(file_get_contents("{$this->git->workspace}/untracked.txt"))->toBe("brand new\n")
        ->and(file_get_contents("{$this->git->workspace}/README.md"))->toBe("edited but never committed\n")
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

test('a push failure keeps the sandbox through the sweep', function () {
    $thread = staleStoppedThread($this->git);
    $this->git->rejectPushes();

    $this->artisan('yak:thread-sandboxes')->assertSuccessful();

    expect(array_column($this->git->calls, 'operation'))->not->toContain('destroy')
        ->and($thread->fresh()->sandbox_state)->not->toBe(ThreadSandboxState::Destroyed)
        ->and(file_get_contents("{$this->git->workspace}/untracked.txt"))->toBe("brand new\n");
});
