<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\User;

it('returns null diff before any turn ended', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->getJson(route('threads.diff.show', $thread))
        ->assertOk()
        ->assertExactJson(['diff' => null]);
});

it('requires a signed-in user', function () {
    $this->getJson(route('threads.diff.show', 1))->assertUnauthorized();
});

it('lists files with counts and flags', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 3, 'additions' => 5, 'deletions' => 2, 'is_truncated' => true]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'a.php', 'additions' => 5, 'deletions' => 2]);
    ThreadDiffFile::factory()->for($diff, 'diff')->binary()->create(['path' => 'b.png']);
    ThreadDiffFile::factory()->for($diff, 'diff')->tooLarge()->create(['path' => 'c.sql']);

    $this->actingAs(User::factory()->create())->getJson(route('threads.diff.show', $thread))
        ->assertOk()
        ->assertJsonPath('diff.filesCount', 3)
        ->assertJsonPath('diff.additions', 5)
        ->assertJsonPath('diff.deletions', 2)
        ->assertJsonPath('diff.isTruncated', true)
        ->assertJsonPath('diff.isTurnRunning', false)
        ->assertJsonPath('diff.files.0.path', 'a.php')
        ->assertJsonPath('diff.files.0.status', 'modified')
        ->assertJsonPath('diff.files.1.isBinary', true)
        ->assertJsonPath('diff.files.1.additions', null)
        ->assertJsonPath('diff.files.2.isTooLarge', true)
        ->assertJsonMissingPath('diff.files.0.patch');
});

it('returns one file\'s patch', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->create();
    $file = ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'a.php', 'patch' => "@@ -1 +1 @@\n-a\n+b\n"]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.diff.files.show', [$thread, $file->id]))
        ->assertOk()
        ->assertJson(['path' => 'a.php', 'oldPath' => null, 'status' => 'modified', 'isBinary' => false, 'isTooLarge' => false, 'patch' => "@@ -1 +1 @@\n-a\n+b\n"]);
});

it('404s a file that belongs to another thread', function () {
    $thread = Thread::factory()->create();
    $otherFile = ThreadDiffFile::factory()->create();

    $this->actingAs(User::factory()->create())->getJson(route('threads.diff.files.show', [$thread, $otherFile->id]))
        ->assertNotFound();
});

it('reports isTurnRunning while a turn runs', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->create();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.diff.show', $thread))
        ->assertJsonPath('diff.isTurnRunning', true);
});

it('hides a private thread from everyone but its owner', function (bool $isAdmin, string $route) {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $diff = ThreadDiff::factory()->for($thread)->create();
    $file = ThreadDiffFile::factory()->for($diff, 'diff')->create();
    $viewer = User::factory()->create($isAdmin ? ['email' => 'admin@example.com'] : []);

    $url = fn (Thread|int $target) => match ($route) {
        'diff' => route('threads.diff.show', $target),
        'file' => route('threads.diff.files.show', [$target, $file->id]),
        'environment' => route('threads.environment.show', $target),
    };

    $missing = $this->actingAs($viewer)->getJson($url(999999));
    $denied = $this->actingAs($viewer)->getJson($url($thread));

    $denied->assertNotFound();
    expect($denied->status())->toBe($missing->status())->and(preg_replace("/\d+$/", '', (string) $denied->json('message')))->toBe(preg_replace("/\d+$/", '', (string) $missing->json('message')));
    $this->actingAs($owner)->getJson($url($thread))->assertOk();
})->with([
    'second user' => false,
    'admin' => true,
])->with(['diff', 'file', 'environment']);
