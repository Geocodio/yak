<?php

use App\Enums\RepositoryRole;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadDiffCapture;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/yak-repo-diff-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->tempDir);
    app()->instance(SandboxManager::class, $this->git);
    $this->thread = Thread::factory()->create(['repo' => 'acme/app', 'branch' => $this->git->branch, 'sandbox_state' => ThreadSandboxState::Running]);
    $this->owner = User::factory()->create();
    $this->thread->update(['owner_user_id' => $this->owner->id]);
});

afterEach(fn () => File::deleteDirectory($this->tempDir));

/** A clone of a fresh bare repository, registered as an extra of the thread. */
function extraCheckout(string $name, RepositoryRole $role): string
{
    $directory = test()->tempDir . "/{$name}";
    $seed = "{$directory}-seed";
    $bare = "{$directory}.git";
    $identity = ['git', '-c', 'user.name=S', '-c', 'user.email=s@example.com'];

    mkdir($seed, 0777, true);
    Process::path($seed)->run(['git', 'init', '-b', 'main'])->throw();
    file_put_contents("{$seed}/README.md", "{$name}\n");
    Process::path($seed)->run([...$identity, 'add', '-A'])->throw();
    Process::path($seed)->run([...$identity, 'commit', '-m', 'init'])->throw();
    Process::path(test()->tempDir)->run(['git', 'clone', '--bare', $seed, $bare])->throw();
    Process::path(test()->tempDir)->run(['git', 'clone', $bare, $directory])->throw();

    ThreadRepository::factory()->create([
        'thread_id' => test()->thread->id,
        'repo' => "acme/{$name}",
        'role' => $role,
        'sandbox_path' => $directory,
    ]);

    return $directory;
}

it('keeps per-repo diffs and excludes the context repo from totals', function () {
    file_put_contents($this->git->workspace . '/primary.txt', "p\n");
    $web = extraCheckout('web', RepositoryRole::Changes);
    $docs = extraCheckout('docs', RepositoryRole::Context);
    file_put_contents("{$web}/web.txt", "one\ntwo\n");
    file_put_contents("{$docs}/a.txt", "x\n");
    file_put_contents("{$docs}/b.txt", "y\n");

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);
    $payload = $this->actingAs($this->owner)->getJson(route('threads.diff.show', $this->thread))->assertOk()->json('diff');

    expect($diff->repositories)->toHaveCount(2)
        ->and($payload['filesCount'])->toBe(2)
        ->and($payload['additions'])->toBe(3)
        ->and($payload['throwawayFilesCount'])->toBe(2)
        ->and(collect($payload['repositories'])->pluck('repo')->all())->toBe(['acme/app', 'acme/web', 'acme/docs'])
        ->and(collect($payload['repositories'])->pluck('isThrowaway')->all())->toBe([false, false, true])
        ->and(collect($payload['files'])->where('repo', 'acme/docs')->count())->toBe(2)
        ->and(collect($payload['files'])->firstWhere('path', 'primary.txt')['repo'])->toBe('acme/app');
});

it('records zero files for a repo with no changes', function () {
    extraCheckout('web', RepositoryRole::Changes);

    $payload = app(ThreadDiffCapture::class)->capture($this->thread);
    $web = $payload->repositories()->firstOrFail();

    expect($web->files_count)->toBe(0)->and($web->is_throwaway)->toBeFalse();
});

it('serves an extra repo file patch', function () {
    $web = extraCheckout('web', RepositoryRole::Changes);
    file_put_contents("{$web}/web.txt", "hello\n");

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);
    $file = $diff->files()->where('path', 'web.txt')->firstOrFail();

    $this->actingAs($this->owner)->getJson(route('threads.diff.files.show', [$this->thread, $file->id]))
        ->assertOk()->assertJsonPath('patch', fn (string $patch) => str_contains($patch, '+hello'));
});

it('returns one repository entry for a primary-only thread', function () {
    app(ThreadDiffCapture::class)->capture($this->thread);

    $this->actingAs($this->owner)->getJson(route('threads.diff.show', $this->thread))
        ->assertJsonCount(1, 'diff.repositories')->assertJsonPath('diff.repositories.0.role', 'primary');
});

it('keeps the primary diff when an extra cannot be read', function () {
    $web = extraCheckout('web', RepositoryRole::Changes);
    File::deleteDirectory($web);
    file_put_contents($this->git->workspace . '/primary.txt', "p\n");

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);

    expect($diff)->not->toBeNull()->and($diff->files_count)->toBe(1)->and($diff->repositories)->toHaveCount(0);
});
