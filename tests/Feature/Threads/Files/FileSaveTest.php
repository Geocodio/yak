<?php

use App\Enums\FileScope;
use App\Enums\ThreadSandboxState;
use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Models\ThreadFileEdit;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Files\FileSaver;
use App\Services\Threads\Files\InvalidFilePath;
use App\Services\Threads\SteeringDelivery;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/widgets']);
    $this->user = User::factory()->create();
});

function queueRead($test, string $bytes): void
{
    $test->sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));
}

function execCommands($test): array
{
    return array_map(fn (array $call): string => $call['arguments']['command'][2], $test->sandboxes->calls);
}

it('writes when the base sha256 matches the file on disk', function () {
    queueRead($this, 'old');
    $this->sandboxes->queueExecResult(Process::result());
    queueRead($this, 'new');

    $outcome = app(FileSaver::class)->save($this->thread, $this->user, FileScope::Repository, 'src/a.ts', 'new', hash('sha256', 'old'), false);

    expect($outcome->saved)->toBeTrue()
        ->and($outcome->written->sha256)->toBe(hash('sha256', 'new'))
        ->and($this->sandboxes->calls[1]['arguments']['stdin'])->toBe(base64_encode('new'))
        ->and($outcome->toArray()['state'])->toBe('saved');
});

it('returns a conflict and writes nothing when the file changed since it was opened', function () {
    queueRead($this, 'changed by claude');

    $outcome = app(FileSaver::class)->save($this->thread, $this->user, FileScope::Repository, 'src/a.ts', 'mine', hash('sha256', 'old'), false);

    expect($outcome->saved)->toBeFalse()
        ->and($outcome->current->content)->toBe('changed by claude')
        ->and($this->sandboxes->calls)->toHaveCount(1)
        ->and(execCommands($this)[0])->not->toContain('base64 -d');
});

it('overwrites a changed file when asked to', function () {
    queueRead($this, 'changed by claude');
    $this->sandboxes->queueExecResult(Process::result());
    queueRead($this, 'mine');

    $outcome = app(FileSaver::class)->save($this->thread, $this->user, FileScope::Repository, 'src/a.ts', 'mine', hash('sha256', 'old'), true);

    expect($outcome->saved)->toBeTrue()->and($this->sandboxes->calls)->toHaveCount(3);
});

it('creates a new file only when the editor had no base', function () {
    $this->sandboxes->queueExecResult(Process::result('', '', 4));
    $this->sandboxes->queueExecResult(Process::result());
    queueRead($this, 'x');

    expect(app(FileSaver::class)->save($this->thread, $this->user, FileScope::Repository, 'new.txt', 'x', '', false)->saved)->toBeTrue();
});

it('reports a conflict when the file was created after the editor opened a missing one', function () {
    queueRead($this, 'someone else');

    expect(app(FileSaver::class)->save($this->thread, $this->user, FileScope::Repository, 'new.txt', 'x', '', false)->saved)->toBeFalse();
});

it('refuses to write a read-only system path', function () {
    expect(fn () => app(FileSaver::class)->save($this->thread, $this->user, FileScope::Sandbox, '/etc/hosts', 'x', '', true))
        ->toThrow(InvalidFilePath::class)
        ->and($this->sandboxes->calls)->toBeEmpty();
});

it('refuses content over the size limit', function () {
    expect(fn () => app(FileSaver::class)->save($this->thread, $this->user, FileScope::Repository, 'a.txt', str_repeat('a', 1_000_001), '', true))
        ->toThrow(InvalidFilePath::class);
});

it('escapes shell syntax in the path', function () {
    queueRead($this, 'old');
    $this->sandboxes->queueExecResult(Process::result());
    queueRead($this, 'new');

    app(FileSaver::class)->save($this->thread, $this->user, FileScope::Repository, 'a $(touch x).txt', 'new', hash('sha256', 'old'), false);

    expect(execCommands($this)[1])->toContain(escapeshellarg('/workspace/widgets/a $(touch x).txt'))
        ->and(execCommands($this)[1])->not->toContain(' $(touch x).txt ');
});

function saveThread(): array
{
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create(['sandbox_state' => ThreadSandboxState::Running]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $delivery = Mockery::mock(SteeringDelivery::class);
    $delivery->shouldReceive('deliver')->andReturn(true);
    app()->instance(SteeringDelivery::class, $delivery);

    return [$thread, $owner];
}

function savePayload(array $overrides = []): array
{
    return ['scope' => 'repo', 'path' => 'src/a.ts', 'content' => 'new', 'base_sha256' => hash('sha256', 'old'), ...$overrides];
}

it('saves over HTTP, records the edit and steers the agent', function () {
    $this->withoutVite();
    [$thread, $owner] = saveThread();
    queueRead($this, 'old');
    $this->sandboxes->queueExecResult(Process::result());
    queueRead($this, 'new');

    $this->actingAs($owner)->putJson(route('threads.files.update', $thread), savePayload())
        ->assertOk()
        ->assertJsonPath('state', 'saved')
        ->assertJsonPath('file.sha256', hash('sha256', 'new'))
        ->assertJsonPath('working', false);

    expect(ThreadFileEdit::query()->sole()->path)->toBe('/workspace/widgets/src/a.ts')
        ->and(PendingSteeringMessage::query()->count())->toBe(1);
});

it('answers 409 with the current file on a conflict and records nothing', function () {
    [$thread, $owner] = saveThread();
    queueRead($this, 'claude wrote this');

    $this->actingAs($owner)->putJson(route('threads.files.update', $thread), savePayload())
        ->assertStatus(409)
        ->assertJsonPath('state', 'conflict')
        ->assertJsonPath('file.content', 'claude wrote this');

    expect(ThreadFileEdit::query()->count())->toBe(0)->and(PendingSteeringMessage::query()->count())->toBe(0);
});

it('keeps a viewer without drive rights out and hides the thread from a stranger', function () {
    [$thread, $owner] = saveThread();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->putJson(route('threads.files.update', $thread), savePayload())->assertForbidden();
    $this->actingAs(User::factory()->create())->putJson(route('threads.files.update', $thread), savePayload())->assertNotFound();

    expect($this->sandboxes->calls)->toBeEmpty();
});

it('answers 422 for a read-only system path and for oversized content', function () {
    [$thread, $owner] = saveThread();

    $this->actingAs($owner)->putJson(route('threads.files.update', $thread), savePayload(['scope' => 'sandbox', 'path' => '/etc/hosts']))->assertStatus(422);
    $this->actingAs($owner)->putJson(route('threads.files.update', $thread), savePayload(['content' => str_repeat('a', 1_000_001)]))->assertStatus(422);

    expect($this->sandboxes->calls)->toBeEmpty();
});

it('accepts empty content and an empty base for a new empty file', function () {
    [$thread, $owner] = saveThread();
    $this->sandboxes->queueExecResult(Process::result('', '', 4));
    $this->sandboxes->queueExecResult(Process::result());
    queueRead($this, '');

    $this->actingAs($owner)->putJson(route('threads.files.update', $thread), savePayload(['content' => '', 'base_sha256' => '']))
        ->assertOk()->assertJsonPath('state', 'saved');
});
