<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Stacks\ChildSandboxProvisioner;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->sandbox = app(SandboxManager::class);
    expect($this->sandbox)->toBeInstanceOf(FakeSandboxManager::class);
    $this->parent = Thread::factory()->create([
        'repo' => 'acme/widgets',
        'branch' => 'yak/parent',
        'sandbox_container' => 'yak-thread-parent',
        'sandbox_state' => ThreadSandboxState::Running,
    ]);
    $this->child = Thread::factory()->child($this->parent)->create([
        'kind' => ThreadKind::Implement,
        'branch' => 'yak/yak/parent/api',
    ]);
});

function execCommands(FakeSandboxManager $sandbox, int $threadId): array
{
    return array_values(array_map(
        fn (array $call): array => $call['arguments'],
        array_filter($sandbox->calls, fn (array $call): bool => $call['operation'] === 'exec' && $call['threadId'] === $threadId),
    ));
}

it('copies the parent sandbox and checks out the child branch', function () {
    app(ChildSandboxProvisioner::class)->provision($this->child);

    $operations = array_column(array_filter($this->sandbox->calls, fn (array $call): bool => $call['threadId'] === $this->child->id), 'operation');
    $child = $this->child->fresh();

    expect($operations)->toBe(['copyFrom', 'start', 'exec'])
        ->and(execCommands($this->sandbox, $this->child->id)[0]['command'])->toBe(['git', 'checkout', '-B', 'yak/yak/parent/api'])
        ->and($child->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and($child->sandbox_container)->not->toBeNull()
        ->and($child->queued_for_slot_at)->toBeNull();
});

it('falls back to a fresh clone plus the parent diff when copyFrom fails', function () {
    $this->sandbox->failNextCopy();
    $this->sandbox->queueExecResult(Process::result("abc123\n"));
    $this->sandbox->queueExecResult(Process::result("src/a.php\n.env\n"));
    $this->sandbox->queueExecResult(Process::result("diff --git a/src/a.php b/src/a.php\n"));
    $this->sandbox->queueExecResult(Process::result("notes.txt\nid_rsa\n"));
    $this->sandbox->queueExecResult(Process::result('QUJD'));

    app(ChildSandboxProvisioner::class)->provision($this->child);

    $operations = array_column(array_filter($this->sandbox->calls, fn (array $call): bool => $call['threadId'] === $this->child->id && $call['operation'] !== 'exec'), 'operation');
    $parentExec = execCommands($this->sandbox, $this->parent->id);
    $childExec = execCommands($this->sandbox, $this->child->id);
    $apply = collect($childExec)->first(fn (array $call): bool => array_slice($call['command'], 0, 2) === ['git', 'apply']);
    $archive = collect($childExec)->first(fn (array $call): bool => $call['command'][0] === 'bash' && str_contains($call['command'][2] ?? '', 'tar -xf'));

    expect($operations)->toContain('create', 'start')
        ->and($parentExec[2]['command'])->toContain(':(exclude).env')
        ->and($parentExec[4]['stdin'])->toBe('notes.txt')
        ->and($apply['stdin'])->toBe("diff --git a/src/a.php b/src/a.php\n")
        ->and($archive['stdin'])->toBe('QUJD')
        ->and($this->child->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('never ships secret files in the fallback diff', function () {
    $this->sandbox->failNextCopy();
    $this->sandbox->queueExecResult(Process::result("abc123\n"));
    $this->sandbox->queueExecResult(Process::result("config/app.php\nconfig/credentials.json\nsecrets.yml\n"));
    $this->sandbox->queueExecResult(Process::result(''));
    $this->sandbox->queueExecResult(Process::result(".env.local\nid_ed25519\nserver.pem\n"));

    app(ChildSandboxProvisioner::class)->provision($this->child);

    $parentExec = execCommands($this->sandbox, $this->parent->id);

    expect($parentExec[2]['command'])->toContain(':(exclude)config/credentials.json', ':(exclude)secrets.yml')
        ->not->toContain(':(exclude)config/app.php')
        ->and($parentExec)->toHaveCount(4);
});

it('queues the child when the parent cannot wake up yet', function () {
    $this->parent->update(['sandbox_state' => ThreadSandboxState::Stopped, 'sandbox_container' => null]);
    $this->sandbox->memoryBytes = 0;

    app(ChildSandboxProvisioner::class)->provision($this->child);

    $child = $this->child->fresh();

    expect($child->sandbox_state)->toBe(ThreadSandboxState::None)
        ->and($child->queued_for_slot_at)->not->toBeNull();
});
