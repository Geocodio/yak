<?php

use App\Enums\DiffFileStatus;
use App\Enums\ThreadSandboxState;
use App\Events\ThreadDiffUpdated;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadDiffCapture;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/yak-diff-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->tempDir);
    app()->instance(SandboxManager::class, $this->git);
    $this->thread = Thread::factory()->create([
        'branch' => $this->git->branch,
        'sandbox_state' => ThreadSandboxState::Running,
    ]);
});

afterEach(fn () => File::deleteDirectory($this->tempDir));

function workspaceWrite(string $relative, string $contents): void
{
    $path = test()->git->workspace . '/' . $relative;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $contents);
}

function workspaceCommit(string $message = 'Agent work'): void
{
    test()->git->exec(test()->thread, ['git', 'add', '-A']);
    test()->git->exec(test()->thread, ['git', '-c', 'user.name=A', '-c', 'user.email=a@example.com', 'commit', '-m', $message]);
}

function capturedFiles(ThreadDiff $diff): array
{
    return $diff->files->keyBy('path')->all();
}

it('captures added, modified, deleted and renamed files with counts and patches', function () {
    workspaceWrite('keep.txt', "one\ntwo\nthree\nfour\nfive\nsix\n");
    workspaceWrite('gone.txt', "bye\n");
    workspaceWrite('old-name.txt', "a\nb\nc\nd\ne\nf\ng\nh\n");
    workspaceCommit('base');
    $this->git->exec($this->thread, ['git', 'push', 'origin', 'HEAD:refs/heads/main']);

    workspaceWrite('keep.txt', "one\ntwo\nthree\nfour\nfive\nSIX\n");
    workspaceWrite('added.txt', "new\n");
    unlink($this->git->workspace . '/gone.txt');
    rename($this->git->workspace . '/old-name.txt', $this->git->workspace . '/new-name.txt');

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);
    $files = capturedFiles($diff);

    expect($diff->files_count)->toBe(4)
        ->and($files['added.txt']->status)->toBe(DiffFileStatus::Added)
        ->and($files['added.txt']->additions)->toBe(1)
        ->and($files['gone.txt']->status)->toBe(DiffFileStatus::Deleted)
        ->and($files['gone.txt']->deletions)->toBe(1)
        ->and($files['keep.txt']->status)->toBe(DiffFileStatus::Modified)
        ->and($files['keep.txt']->additions)->toBe(1)
        ->and($files['keep.txt']->deletions)->toBe(1)
        ->and($files['keep.txt']->patch)->toContain('+SIX')
        ->and($files['new-name.txt']->status)->toBe(DiffFileStatus::Renamed)
        ->and($files['new-name.txt']->old_path)->toBe('old-name.txt')
        ->and($diff->additions)->toBe(2)
        ->and($diff->deletions)->toBe(2);
});

it('diffs commits ahead of the default branch together with working tree changes', function () {
    workspaceWrite('committed.txt', "committed\n");
    workspaceCommit();
    workspaceWrite('loose.txt', "loose\n");

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);

    expect(array_keys(capturedFiles($diff)))->toBe(['committed.txt', 'loose.txt'])
        ->and($diff->base_sha)->toBe($this->git->git(['rev-parse', 'main']))
        ->and($diff->head_sha)->toBe(trim($this->git->exec($this->thread, ['git', 'rev-parse', 'HEAD'])->output()));
});

it('includes untracked files and leaves the agent\'s own index untouched', function () {
    workspaceWrite('untracked.txt', "hello\n");
    $before = $this->git->exec($this->thread, ['git', 'status', '--porcelain'])->output();

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);

    expect(capturedFiles($diff))->toHaveKey('untracked.txt')
        ->and($this->git->exec($this->thread, ['git', 'status', '--porcelain'])->output())->toBe($before)
        ->and($before)->toContain('?? untracked.txt');
});

it('lists a binary file without a patch', function () {
    workspaceWrite('image.bin', "\0\1\2\0binary\0");

    $file = capturedFiles(app(ThreadDiffCapture::class)->capture($this->thread))['image.bin'];

    expect($file->is_binary)->toBeTrue()
        ->and($file->additions)->toBeNull()
        ->and($file->deletions)->toBeNull()
        ->and($file->patch)->toBeNull();
});

it('handles names with spaces, non-ASCII characters and a leading dash', function () {
    workspaceWrite('with space.txt', "a\n");
    workspaceWrite('caf' . "\u{e9}" . '-日本.txt', "b\n");
    workspaceWrite('-rf', "c\n");

    $files = capturedFiles(app(ThreadDiffCapture::class)->capture($this->thread));

    expect(array_keys($files))->toEqualCanonicalizing(['with space.txt', 'caf' . "\u{e9}" . '-日本.txt', '-rf'])
        ->and($files['-rf']->patch)->toContain('+c')
        ->and($files['with space.txt']->patch)->toContain('+a')
        ->and($files['caf' . "\u{e9}" . '-日本.txt']->patch)->toContain('+b');
});

it('marks a file over the per-file cap as too large with no patch', function () {
    config(['yak.threads.diff_max_bytes' => 4000]);
    workspaceWrite('huge.txt', str_repeat("line of text\n", 400));
    workspaceWrite('small.txt', "tiny\n");

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);
    $files = capturedFiles($diff);

    expect($files['huge.txt']->is_too_large)->toBeTrue()
        ->and($files['huge.txt']->patch)->toBeNull()
        ->and($files['huge.txt']->additions)->toBe(400)
        ->and($files['small.txt']->patch)->toContain('+tiny')
        ->and($diff->is_truncated)->toBeFalse();
});

it('stops storing patches past the total cap and sets isTruncated', function () {
    config(['yak.threads.diff_max_bytes' => 1000]);

    foreach (range(1, 8) as $number) {
        workspaceWrite("file-{$number}.txt", str_repeat('x', 40) . "\n");
    }

    $diff = app(ThreadDiffCapture::class)->capture($this->thread);
    $files = $diff->files;

    expect($diff->files_count)->toBe(8)
        ->and($diff->is_truncated)->toBeTrue()
        ->and($files->whereNotNull('patch'))->not->toBeEmpty()
        ->and($files->where('is_too_large', true))->not->toBeEmpty()
        ->and($files->where('is_too_large', true)->whereNotNull('patch'))->toBeEmpty()
        ->and($files->sum(fn ($file) => strlen((string) $file->patch)))->toBeLessThanOrEqual(1000);
});

it('scrubs invalid UTF-8 so the patch json encodes', function () {
    workspaceWrite('latin.txt', "caf\xE9\n");

    $file = capturedFiles(app(ThreadDiffCapture::class)->capture($this->thread))['latin.txt'];

    expect($file->is_binary)->toBeFalse()
        ->and(json_encode(['patch' => $file->patch]))->not->toBeFalse();
});

it('stores an empty diff when nothing changed', function () {
    $diff = app(ThreadDiffCapture::class)->capture($this->thread);

    expect($diff)->not->toBeNull()
        ->and($diff->files_count)->toBe(0)
        ->and($diff->files)->toBeEmpty()
        ->and($diff->base_sha)->toBe($diff->head_sha)
        ->and(ThreadDiff::query()->where('thread_id', $this->thread->id)->count())->toBe(1);
});

it('replaces the previous diff and dispatches ThreadDiffUpdated', function () {
    Event::fake([ThreadDiffUpdated::class]);
    workspaceWrite('one.txt', "1\n");
    $first = app(ThreadDiffCapture::class)->capture($this->thread);

    workspaceWrite('two.txt', "2\n");
    $second = app(ThreadDiffCapture::class)->capture($this->thread);

    expect(ThreadDiff::query()->where('thread_id', $this->thread->id)->count())->toBe(1)
        ->and(ThreadDiff::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and($second->files_count)->toBe(2);

    Event::assertDispatchedTimes(ThreadDiffUpdated::class, 2);
    Event::assertDispatched(ThreadDiffUpdated::class, fn (ThreadDiffUpdated $event) => $event->thread->is($this->thread) && $event->diff->is($second));
});

it('keeps the previous diff and returns null when git fails', function () {
    Exceptions::fake();
    Event::fake([ThreadDiffUpdated::class]);
    workspaceWrite('one.txt', "1\n");
    $previous = app(ThreadDiffCapture::class)->capture($this->thread);
    Event::fake([ThreadDiffUpdated::class]);

    File::deleteDirectory($this->git->workspace . '/.git');

    expect(app(ThreadDiffCapture::class)->capture($this->thread))->toBeNull()
        ->and(ThreadDiff::query()->where('thread_id', $this->thread->id)->value('id'))->toBe($previous->id);

    Exceptions::assertReported(RuntimeException::class);
    Event::assertNotDispatched(ThreadDiffUpdated::class);
});
