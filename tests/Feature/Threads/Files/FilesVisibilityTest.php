<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->withoutVite();
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $this->owner = User::factory()->create();
    $this->viewer = User::factory()->create();
});

function filesThread(ThreadSandboxState $state = ThreadSandboxState::Running, array $attributes = []): Thread
{
    $thread = Thread::factory()->private(test()->owner)->create(['sandbox_state' => $state, ...$attributes]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/widgets']);
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => test()->viewer->id]);

    return $thread;
}

it('lists a folder for a person who can drive and marks changed files', function () {
    $thread = filesThread();
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 1]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'src/a.ts']);
    $this->sandboxes->queueExecResult(Process::result("d\t0\tsrc\0f\t5\ta.txt\0"));

    $this->actingAs($this->owner)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))
        ->assertOk()
        ->assertJsonPath('state', 'ready')
        ->assertJsonPath('root', '/workspace/widgets')
        ->assertJsonPath('entries.0.name', 'src')
        ->assertJsonPath('changedPaths', ['src/a.ts']);
});

it('lets a viewer read the repository tree and a file but not edit or browse the sandbox', function () {
    $thread = filesThread();
    $this->sandboxes->queueExecResult(Process::result("f\t5\ta.txt\0"));
    $this->sandboxes->queueExecResult(Process::result("2\n" . base64_encode('hi') . "\n-\n"));

    $this->actingAs($this->viewer)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))->assertOk();
    $this->actingAs($this->viewer)->getJson(route('threads.files.show', [$thread, 'scope' => 'repo', 'path' => 'a.txt']))
        ->assertOk()
        ->assertJsonPath('content', 'hi')
        ->assertJsonPath('canEdit', false);
    $this->actingAs($this->viewer)->getJson(route('threads.files.tree', [$thread, 'scope' => 'sandbox']))->assertForbidden();
});

it('tells the owner a writable text file can be edited', function () {
    $thread = filesThread();
    $this->sandboxes->queueExecResult(Process::result("2\n" . base64_encode('hi') . "\n-\n"));

    $this->actingAs($this->owner)->getJson(route('threads.files.show', [$thread, 'scope' => 'repo', 'path' => 'a.txt']))
        ->assertOk()
        ->assertJsonPath('canEdit', true);
});

it('does not let the owner edit a file in a read-only system folder', function () {
    $thread = filesThread();
    $this->sandboxes->queueExecResult(Process::result("2\n" . base64_encode('hi') . "\n-\n"));

    $this->actingAs($this->owner)->getJson(route('threads.files.show', [$thread, 'scope' => 'sandbox', 'path' => '/etc/hosts']))
        ->assertOk()
        ->assertJsonPath('canEdit', false);
});

it('answers a stranger and an admin exactly like a missing thread', function () {
    $thread = filesThread();
    $stranger = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$stranger->id]]);

    foreach (['threads.files.tree', 'threads.files.show'] as $name) {
        $hidden = $this->actingAs($stranger)->getJson(route($name, [$thread, 'scope' => 'repo', 'path' => 'a']));
        $missing = $this->actingAs($stranger)->getJson(route($name, [999_999, 'scope' => 'repo', 'path' => 'a']));

        expect($hidden->getStatusCode())->toBe(404)->and($missing->getStatusCode())->toBe(404)
            ->and($hidden->json('exception'))->toBe($missing->json('exception'));
    }

    expect($this->sandboxes->calls)->toBe([]);
});

it('answers 422 for an unsafe path and runs nothing', function () {
    $thread = filesThread();

    $this->actingAs($this->owner)->getJson(route('threads.files.show', [$thread, 'scope' => 'repo', 'path' => '../etc/passwd']))
        ->assertStatus(422)
        ->assertJsonStructure(['message']);
    expect($this->sandboxes->calls)->toBe([]);
});

it('answers 422 for an unknown scope', function () {
    $this->actingAs($this->owner)->getJson(route('threads.files.tree', [filesThread(), 'scope' => 'nope']))->assertStatus(422);
});

it('answers on_device for a thread owned by a computer', function () {
    $thread = filesThread(attributes: ['owner_side' => ThreadOwnerSide::Device]);

    $this->actingAs($this->owner)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))
        ->assertStatus(409)
        ->assertExactJson(['state' => 'on_device']);
});

it('offers a restore to the owner but not a viewer when the sandbox is destroyed', function () {
    $thread = filesThread(ThreadSandboxState::Destroyed);

    $this->actingAs($this->owner)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))
        ->assertStatus(409)->assertExactJson(['state' => 'destroyed', 'canRestore' => true]);
    $this->actingAs($this->viewer)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))
        ->assertStatus(409)->assertExactJson(['state' => 'destroyed', 'canRestore' => false]);
});

it('answers closed_destroyed for a closed thread', function () {
    $thread = filesThread(ThreadSandboxState::Destroyed, ['status' => ThreadStatus::Closed]);

    $this->actingAs($this->owner)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))
        ->assertStatus(409)->assertExactJson(['state' => 'closed_destroyed']);
});

it('does not wake a paused sandbox for a viewer', function () {
    $thread = filesThread(ThreadSandboxState::Paused);

    $this->actingAs($this->viewer)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))
        ->assertStatus(409)->assertJsonPath('state', 'waking');

    expect(collect($this->sandboxes->calls)->pluck('operation')->all())->not->toContain('resume');
});

it('wakes a paused sandbox for the owner and lists the folder', function () {
    $thread = filesThread(ThreadSandboxState::Paused);
    $this->sandboxes->queueExecResult(Process::result("f\t5\ta.txt\0"));

    $this->actingAs($this->owner)->getJson(route('threads.files.tree', [$thread, 'scope' => 'repo']))
        ->assertOk()->assertJsonPath('entries.0.name', 'a.txt');

    expect(collect($this->sandboxes->calls)->pluck('operation')->all())->toContain('resume');
});
