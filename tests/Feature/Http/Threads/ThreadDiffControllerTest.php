<?php

use App\Enums\DiffFileStatus;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\User;

function diffFor(Thread $thread, string $path = 'a.php'): ThreadDiff
{
    $diff = ThreadDiff::query()->create([
        'thread_id' => $thread->id, 'base_sha' => 'b', 'head_sha' => 'h',
        'files_count' => 1, 'additions' => 1, 'deletions' => 0, 'captured_at' => now(),
    ]);
    $diff->files()->create(['path' => $path, 'status' => DiffFileStatus::Modified, 'additions' => 1, 'deletions' => 0, 'patch' => '@@ patch']);

    return $diff;
}

it('returns the file list and then one file\'s patch', function () {
    $thread = Thread::factory()->create();
    $file = diffFor($thread)->files->first();
    $this->actingAs(User::factory()->create());

    $this->getJson(route('threads.diff.show', $thread))
        ->assertOk()
        ->assertJsonPath('diff.baseSha', 'b')
        ->assertJsonPath('diff.isTurnRunning', false)
        ->assertJsonPath('diff.files.0.path', 'a.php')
        ->assertJsonPath('diff.files.0.status', 'modified')
        ->assertJsonMissingPath('diff.files.0.patch');

    $this->getJson(route('threads.diff.files.show', [$thread, $file->id]))
        ->assertOk()
        ->assertExactJson(['path' => 'a.php', 'status' => 'modified', 'isBinary' => false, 'isTooLarge' => false, 'patch' => '@@ patch']);
});

it('reports a running turn and an empty diff', function () {
    $thread = Thread::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->getJson(route('threads.diff.show', $thread))->assertOk()->assertExactJson(['diff' => null]);

    diffFor($thread);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => 'running']);

    $this->getJson(route('threads.diff.show', $thread))->assertJsonPath('diff.isTurnRunning', true);
});

it('404s a file of another thread', function () {
    $other = diffFor(Thread::factory()->create())->files->first();
    $this->actingAs(User::factory()->create());

    $this->getJson(route('threads.diff.files.show', [Thread::factory()->create(), $other->id]))->assertNotFound();
});
