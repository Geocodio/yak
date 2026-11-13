<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
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

    $url = fn (Thread|string $target) => match ($route) {
        'diff' => route('threads.diff.show', $target),
        'file' => route('threads.diff.files.show', [$target, $file->id]),
        'environment' => route('threads.environment.show', $target),
    };

    $missing = $this->actingAs($viewer)->getJson($url('zzzzzzzz'));
    $denied = $this->actingAs($viewer)->getJson($url($thread));

    $denied->assertNotFound();
    expect($denied->status())->toBe($missing->status())->and(str_replace($thread->public_id, 'ID', (string) $denied->json('message')))->toBe(str_replace('zzzzzzzz', 'ID', (string) $missing->json('message')));
    $this->actingAs($owner)->getJson($url($thread))->assertOk();
})->with([
    'second user' => false,
    'admin' => true,
])->with(['diff', 'file', 'environment']);

it('reports each repository branch and pull request url', function () {
    $thread = Thread::factory()->create(['repo' => 'acme/app', 'branch' => 'yak/main-branch']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app', 'branch' => 'yak/app', 'pull_request_url' => 'https://github.com/acme/app/pull/1']);
    ThreadRepository::factory()->context()->create(['thread_id' => $thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs']);
    $diff = ThreadDiff::factory()->for($thread)->create();
    $diff->repositories()->create(['repo' => 'acme/docs', 'role' => 'context', 'base_sha' => 'a', 'head_sha' => 'b', 'files_count' => 0, 'is_throwaway' => true]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.diff.show', $thread))
        ->assertOk()
        ->assertJsonPath('diff.repositories.0.branch', 'yak/app')
        ->assertJsonPath('diff.repositories.0.pullRequestUrl', 'https://github.com/acme/app/pull/1')
        ->assertJsonPath('diff.repositories.1.branch', null)
        ->assertJsonPath('diff.repositories.1.pullRequestUrl', null);
});
