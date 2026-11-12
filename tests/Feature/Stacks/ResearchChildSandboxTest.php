<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Stacks\ResearchChildRunner;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadCloser;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->sandbox = app(SandboxManager::class);
    $this->account = ClaudeAccount::factory()->create();
    $this->parent = Thread::factory()->create([
        'sandbox_container' => 'yak-thread-parent',
        'sandbox_host' => 'local',
        'sandbox_state' => ThreadSandboxState::Running,
        'claude_account_id' => $this->account->id,
    ]);
    $this->child = Thread::factory()->child($this->parent)->create([
        'name' => 'scout',
        'kind' => ThreadKind::Research,
        'claude_account_id' => $this->account->id,
    ]);
    $this->runner = app(ResearchChildRunner::class);
});

function startedResearchChild($test): void
{
    $test->sandbox->queueExecResult(Process::result());
    $test->sandbox->queueExecResult(Process::result());
    $test->sandbox->queueExecResult(Process::result("2: eth0    inet 10.0.0.7/24 scope global eth0\n"));
    $test->runner->prepare($test->child);
    $test->runner->start($test->child->fresh(), $test->account, 'research prompt');
}

it('gives the child its own config dir and worktree path', function () {
    $this->runner->prepare($this->child);

    $child = $this->child->fresh();
    $commands = array_column(array_filter($this->sandbox->calls, fn (array $call): bool => $call['operation'] === 'exec' && $call['threadId'] === $this->parent->id), 'arguments');

    expect($child->sandbox_container)->toBe('yak-thread-parent')
        ->and($child->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and($child->claude_config_dir)->toBe("/home/yak/.claude-{$child->id}")
        ->and($child->sharesParentSandbox())->toBeTrue()
        ->and(array_column($commands, 'command')[0])->toBe(['mkdir', '-p', "/home/yak/.claude-{$child->id}"])
        ->and(array_column($commands, 'command')[1][2])->toContain('git worktree add --detach')
        ->and(array_column($commands, 'command')[1][4])->toBe('/workspace-research/scout');
});

it('resolves the research child placeholder against the parent container', function () {
    startedResearchChild($this);

    $start = collect(app(BrokerStreams::class)->commands("threads:{$this->child->id}"))->firstWhere('type', 'start_runner');
    $placeholder = $start['start']['placeholder'];

    expect($start['cwd'])->toBe('/workspace-research/scout')
        ->and($start['start']['cwd'])->toBe('/workspace-research/scout')
        ->and($start['container'])->toBe('yak-thread-parent')
        ->and($start['start']['claudeConfigDir'])->toBe("/home/yak/.claude-{$this->child->id}")
        ->and(app(AuthProxyPlaceholders::class)->resolve($placeholder, '10.0.0.7')?->is($this->account))->toBeTrue();
});

it('revokes parent and child placeholders when the parent sandbox is destroyed', function () {
    startedResearchChild($this);
    app(ThreadCommands::class)->startRunner($this->parent, $this->account, null, 'parent prompt');
    $commands = app(BrokerStreams::class);
    $childPlaceholder = collect($commands->commands("threads:{$this->child->id}"))->firstWhere('type', 'start_runner')['start']['placeholder'];
    $parentPlaceholder = collect($commands->commands("threads:{$this->parent->id}"))->firstWhere('type', 'start_runner')['start']['placeholder'];
    $resolver = app(AuthProxyPlaceholders::class);
    $ip = '';

    expect($resolver->resolve($childPlaceholder, '10.0.0.7'))->not->toBeNull();

    app(ThreadSandbox::class)->destroy($this->parent);

    // The parent exec for its container ip returned an empty address in the fake, so check by every ip it could be bound to.
    expect($resolver->resolve($childPlaceholder, '10.0.0.7'))->toBeNull()
        ->and($resolver->resolve($parentPlaceholder, $ip))->toBeNull()
        ->and($this->parent->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($this->child->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
});

it('closing a research child leaves the parent container running', function () {
    startedResearchChild($this);

    app(ThreadCloser::class)->close($this->child->fresh(), 'finished');

    $operations = array_column(array_filter($this->sandbox->calls, fn (array $call): bool => $call['operation'] !== 'exec'), 'operation');
    $cleanup = collect($this->sandbox->calls)->last(fn (array $call): bool => $call['operation'] === 'exec' && $call['threadId'] === $this->parent->id)['arguments']['command'];

    expect($operations)->not->toContain('destroy', 'stop', 'pause')
        ->and($cleanup[2])->toContain('git worktree remove')
        ->and($cleanup[4])->toBe('/workspace-research/scout')
        ->and($cleanup[5])->toBe("/home/yak/.claude-{$this->child->id}")
        ->and($this->parent->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and($this->parent->fresh()->status)->toBe(ThreadStatus::Open)
        ->and($this->child->fresh()->status)->toBe(ThreadStatus::Closed);
});

it('kills the child runner when its sandbox is released', function () {
    startedResearchChild($this);

    app(ThreadSandbox::class)->destroy($this->child->fresh());

    expect(collect(app(BrokerStreams::class)->commands("threads:{$this->child->id}"))->pluck('type')->all())->toContain('kill')
        ->and($this->child->fresh()->sandbox_container)->toBeNull();
});

it('keeps the parent sandbox busy while only the child has a turn', function () {
    $this->runner->prepare($this->child);
    expect($this->parent->isSandboxBusy())->toBeFalse();

    TaskRun::factory()->create(['thread_id' => $this->child->id, 'turn_state' => TurnState::Running]);

    app(ThreadSandbox::class)->pauseIfIdle($this->parent);

    expect($this->parent->fresh()->isSandboxBusy())->toBeTrue()
        ->and($this->parent->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('recreates the worktree at the parent head for a later message', function () {
    $this->runner->prepare($this->child);
    $this->parent->update(['sandbox_state' => ThreadSandboxState::Paused]);
    $this->sandbox->pause($this->parent);

    $this->runner->refreshWorktree($this->child->fresh());

    $commands = collect($this->sandbox->calls)->where('operation', 'exec')->pluck('arguments.command')->map(fn (array $command): string => implode(' ', $command));

    expect($commands->filter(fn (string $command): bool => str_contains($command, 'git worktree remove')))->toHaveCount(1)
        ->and($commands->filter(fn (string $command): bool => str_contains($command, 'git worktree add')))->toHaveCount(2)
        ->and($this->parent->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('restores the child session into a recreated config dir on refreshWorktree', function () {
    $this->runner->prepare($this->child);
    $this->parent->update(['sandbox_state' => ThreadSandboxState::Paused]);
    $this->sandbox->pause($this->parent);

    $this->runner->refreshWorktree($this->child->fresh());

    $commands = collect($this->sandbox->calls)->where('operation', 'exec')->pluck('arguments.command')->map(fn (array $command): string => implode(' ', $command));

    expect($commands->contains("mkdir -p /home/yak/.claude-{$this->child->id}"))->toBeTrue()
        ->and(app(SessionFileSync::class)->restored)->toBe([$this->child->id]);
});
