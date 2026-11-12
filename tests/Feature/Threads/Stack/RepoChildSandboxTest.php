<?php

use App\Enums\RepositoryRole;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Stacks\ChildOutcomeRecorder;
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
        'repo' => 'acme/website',
        'kind' => ThreadKind::Implement,
        'branch' => 'yak/yak/parent/site',
        'sandbox_container' => null,
        'sandbox_state' => ThreadSandboxState::None,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $this->child->id, 'repo' => 'acme/website', 'role' => RepositoryRole::Primary, 'sandbox_path' => '/workspace', 'branch' => 'yak/yak/parent/site']);
});

function repoChildCalls($test, int $threadId): array
{
    return array_values(array_filter($test->sandbox->calls, fn (array $call): bool => $call['threadId'] === $threadId));
}

it('restores from its own template and never copies the parent', function () {
    app(ChildSandboxProvisioner::class)->provision($this->child);

    $operations = array_column(repoChildCalls($this, $this->child->id), 'operation');
    $touchedParent = collect(repoChildCalls($this, $this->parent->id))->isNotEmpty();

    expect($operations)->not->toContain('copyFrom')
        ->and($operations)->toContain('create', 'start')
        ->and($touchedParent)->toBeFalse()
        ->and($this->child->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('checks out the child branch after the restore', function () {
    app(ChildSandboxProvisioner::class)->provision($this->child);

    $commands = collect(repoChildCalls($this, $this->child->id))->where('operation', 'exec')->pluck('arguments.command');

    expect($commands->contains(['git', 'checkout', '-B', 'yak/yak/parent/site']))->toBeTrue();
});

it('still copies the parent for a child in the same repo', function () {
    $same = Thread::factory()->child($this->parent)->create(['repo' => 'acme/widgets', 'kind' => ThreadKind::Implement, 'branch' => 'yak/yak/parent/same']);

    app(ChildSandboxProvisioner::class)->provision($same);

    expect(array_column(repoChildCalls($this, $same->id), 'operation'))->toContain('copyFrom');
});

it('skips the branch merge for a repo child and reports its pull request url', function () {
    $this->child->update(['sandbox_container' => 'yak-thread-child', 'sandbox_state' => ThreadSandboxState::Running]);
    $task = YakTask::factory()->create(['thread_id' => $this->child->id, 'repo' => 'acme/website', 'pr_url' => 'https://github.com/acme/website/pull/9']);
    $run = TaskRun::factory()->create(['thread_id' => $this->child->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Completed, 'last_result_event' => ['result' => 'Updated the copy.']]);
    foreach ([" M a.php\n", "1\n", '', ''] as $output) {
        $this->sandbox->queueExecResult(Process::result($output));
    }
    $this->sandbox->queueExecResult(Process::result());
    $this->sandbox->queueExecResult(Process::result("abc123\n"));

    app(ChildOutcomeRecorder::class)->recordFinished($this->child, $run);

    $row = StackThreadMessage::query()->sole();

    expect($row->kind)->toBe(StackThreadMessageKind::Result)
        ->and($row->branch)->toBeNull()
        ->and($row->text)->toContain('Updated the copy.')
        ->toContain('https://github.com/acme/website/pull/9')
        ->toContain('Do not merge it into your branch');
});
