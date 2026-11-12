<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Jobs\PrepareTeleportUpJob;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Teleport\OwnershipMover;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Support\LocalGitSandboxManager;

require_once __DIR__ . '/CliTestHelpers.php';

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/yak-up-wait-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->tempDir);
    $this->git->branch = 'yak/up';
    $this->git->useBranchFromDefault('yak/up');
    $this->git->pushFromElsewhere('remote.txt', "pushed\n");
    $this->head = $this->git->git(['rev-parse', 'refs/heads/yak/up']);
    app()->instance(SandboxManager::class, $this->git);

    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    [$this->device] = makeDevice();
    $this->device->update(['os' => 'darwin', 'arch' => 'arm64']);

    $this->thread = Thread::factory()->stopped()->ownedByDevice($this->device->id)->create(['repo' => 'acme/widgets', 'branch' => 'yak/up', 'local_repo_path' => '/Users/ada/widgets']);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets', 'branch' => 'yak/up', 'sandbox_path' => $this->git->workspace]);

    $this->order = [];
    $this->ownerAtRestore = null;
    $this->sessions = Mockery::mock(SessionFileSync::class);
    $this->sessions->shouldReceive('restoreIntoSandbox')->andReturnUsing(function (Thread $thread) {
        $this->order[] = 'restore';
        $this->ownerAtRestore = Thread::withoutGlobalScopes()->find($thread->id)->owner_side;
    })->byDefault();
    app()->instance(SessionFileSync::class, $this->sessions);

    $this->wakes = [];
    $this->sandbox = Mockery::mock(ThreadSandbox::class);
    $this->sandbox->shouldReceive('ensureAwake')->andReturnUsing(function (Thread $thread) {
        $wake = array_shift($this->wakes) ?? SandboxWake::ready();

        if ($wake->ready) {
            $thread->forceFill(['sandbox_state' => ThreadSandboxState::Running])->save();
        }

        return $wake;
    });
    app()->instance(ThreadSandbox::class, $this->sandbox);
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->tempDir);
});

/**
 * @param  array<string, mixed>  $payload
 */
function preparingUpTeleport(object $test, array $payload = []): Teleport
{
    return Teleport::factory()->up()->inState(TeleportState::Preparing)->create([
        'thread_id' => $test->thread->id, 'device_id' => $test->device->id, 'session_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'branch_heads' => ['acme/widgets' => $test->head],
        'payload' => ['prepare_started_at' => now()->toIso8601String(), ...$payload],
    ]);
}

function runUpJob(Teleport $teleport): PrepareTeleportUpJob
{
    $job = new PrepareTeleportUpJob($teleport->id);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('keeps the thread on the device until the slot frees and the sandbox holds the pushed head', function () {
    $this->wakes = [SandboxWake::waitingForSlot(), SandboxWake::ready()];
    $teleport = preparingUpTeleport($this);

    $first = runUpJob($teleport);

    expect($teleport->refresh()->state)->toBe(TeleportState::Preparing)
        ->and($teleport->payload['waiting_for_slot'])->toBeTrue()
        ->and(Thread::withoutGlobalScopes()->find($this->thread->id)->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and($this->order)->toBe([])
        ->and($this->git->calls)->toBe([]);
    $first->assertReleased((int) config('yak.teleport.slot_poll_seconds'));

    runUpJob($teleport);

    expect($this->git->git(['rev-parse', 'HEAD'], $this->git->workspace))->toBe($this->head)
        ->and($this->order)->toBe(['restore'])
        ->and($this->ownerAtRestore)->toBe(ThreadOwnerSide::Device)
        ->and($teleport->refresh()->state)->toBe(TeleportState::Completed)
        ->and(Thread::withoutGlobalScopes()->find($this->thread->id)->owner_side)->toBe(ThreadOwnerSide::Yak);
});

it('waits the same way while another process holds the restore lock', function () {
    $this->wakes = [SandboxWake::restoring(), SandboxWake::ready()];
    $teleport = preparingUpTeleport($this);

    $first = runUpJob($teleport);

    expect($teleport->refresh()->state)->toBe(TeleportState::Preparing)
        ->and($teleport->payload['waiting_for_slot'])->toBeFalse()
        ->and($this->git->calls)->toBe([])
        ->and(Thread::withoutGlobalScopes()->find($this->thread->id)->owner_side)->toBe(ThreadOwnerSide::Device);
    $first->assertReleased((int) config('yak.teleport.slot_poll_seconds'));

    runUpJob($teleport);

    expect($teleport->refresh()->failure_message)->toBeNull()->and($teleport->state)->toBe(TeleportState::Completed);
});

it('gives up after the slot wait and leaves the thread on the device', function () {
    $start = Carbon::parse('2026-11-11 10:00:00');
    Carbon::setTestNow($start);
    $this->wakes = [SandboxWake::waitingForSlot(), SandboxWake::waitingForSlot()];
    $teleport = preparingUpTeleport($this);
    $this->sessions->shouldNotReceive('restoreIntoSandbox');
    $mover = Mockery::mock(OwnershipMover::class);
    $mover->shouldNotReceive('toYak');
    app()->instance(OwnershipMover::class, $mover);

    runUpJob($teleport)->assertReleased((int) config('yak.teleport.slot_poll_seconds'));
    expect($teleport->refresh()->state)->toBe(TeleportState::Preparing);

    Carbon::setTestNow($start->copy()->addMinutes((int) config('yak.teleport.slot_wait_minutes') + 1));
    $second = runUpJob($teleport);

    expect($teleport->refresh()->state)->toBe(TeleportState::Failed)
        ->and($teleport->failure_code)->toBe('no_sandbox_slot')
        ->and(Thread::withoutGlobalScopes()->find($this->thread->id)->owner_side)->toBe(ThreadOwnerSide::Device);
    $second->assertNotReleased();
});

it('starts the slot clock when the claim time is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));
    $this->wakes = [SandboxWake::waitingForSlot()];
    $teleport = preparingUpTeleport($this);
    $teleport->forceFill(['payload' => []])->save();

    runUpJob($teleport);

    expect($teleport->refresh()->state)->toBe(TeleportState::Preparing)
        ->and(Carbon::parse($teleport->payload['prepare_started_at'])->equalTo(now()))->toBeTrue();
});

it('writes the teleport environment note for a sandbox that was restored', function () {
    $this->thread->forceFill(['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null])->save();
    $teleport = preparingUpTeleport($this);

    runUpJob($teleport);

    $note = collect($this->git->calls)->first(fn (array $call): bool => str_contains((string) ($call['arguments']['stdin'] ?? ''), 'Machine switch'));
    expect($note)->not->toBeNull();
});
