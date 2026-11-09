<?php

use App\Enums\DiffFileStatus;
use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadEvent;
use App\Services\IncusSandboxManager;
use App\Services\Threads\ThreadDiffCapture;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Process;

/**
 * Fakes the sandbox's git. $nameStatus and $numstat are NUL separated like git's -z output;
 * $patches maps a file path to its patch text.
 *
 * @param  list<string>  $nameStatus
 * @param  list<string>  $numstat
 * @param  array<string, string>  $patches
 * @return list<string> every command that ran
 */
function fakeGit(array $nameStatus, array $numstat, array $patches = [], bool $fails = false): ArrayObject
{
    $commands = new ArrayObject;

    test()->mock(IncusSandboxManager::class, function ($mock) use ($nameStatus, $numstat, $patches, $fails, $commands) {
        $mock->shouldReceive('pause')->zeroOrMoreTimes();
        $mock->shouldReceive('run')->andReturnUsing(function (string $container, string $command) use ($nameStatus, $numstat, $patches, $fails, $commands) {
            $commands[] = $command;

            if ($fails && str_contains($command, 'merge-base')) {
                return Process::result(errorOutput: 'fatal: no merge base', exitCode: 128);
            }

            return Process::result(output: match (true) {
                str_contains($command, 'merge-base') => "base111\nhead222\n",
                str_contains($command, '--name-status') => implode("\0", $nameStatus) . "\0",
                str_contains($command, '--numstat') => implode("\0", $numstat) . "\0",
                str_contains($command, 'head -c') => collect($patches)->first(fn ($patch, $path) => str_contains($command, escapeshellarg($path)), ''),
                default => '',
            });
        });
    });

    return $commands;
}

function captureFor(Thread $thread): ?ThreadDiff
{
    return app(ThreadDiffCapture::class)->capture($thread, 'yak-thread-1');
}

it('records added, modified, deleted and renamed files with counts', function () {
    $thread = Thread::factory()->create();
    fakeGit(
        ['A', 'new.php', 'M', 'app/a.php', 'D', 'gone.php', 'R095', 'old.php', 'moved.php'],
        ["3\t0\tnew.php", "2\t1\tapp/a.php", "0\t4\tgone.php", "1\t1\t\0old.php\0moved.php"],
        ['new.php' => 'patch-new', 'app/a.php' => 'patch-a', 'gone.php' => 'patch-gone', 'moved.php' => 'patch-moved'],
    );

    $diff = captureFor($thread);

    expect($diff->base_sha)->toBe('base111')
        ->and($diff->head_sha)->toBe('head222')
        ->and($diff->files_count)->toBe(4)
        ->and($diff->additions)->toBe(6)
        ->and($diff->deletions)->toBe(6)
        ->and($diff->is_truncated)->toBeFalse();

    $files = $diff->files->keyBy('path');
    expect($files['new.php']->status)->toBe(DiffFileStatus::Added)
        ->and($files['app/a.php']->status)->toBe(DiffFileStatus::Modified)
        ->and($files['gone.php']->status)->toBe(DiffFileStatus::Deleted)
        ->and($files['moved.php']->status)->toBe(DiffFileStatus::Renamed)
        ->and($files['moved.php']->old_path)->toBe('old.php')
        ->and($files['moved.php']->additions)->toBe(1)
        ->and($files['app/a.php']->patch)->toBe("patch-a\n");
    expect(ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'diff.updated')->first()->payload)
        ->toMatchArray(['filesCount' => 4, 'additions' => 6, 'deletions' => 6]);
});

it('lists a binary file without content', function () {
    $thread = Thread::factory()->create();
    fakeGit(['A', 'logo.png'], ["-\t-\tlogo.png"]);

    $file = captureFor($thread)->files->first();

    expect($file->is_binary)->toBeTrue()
        ->and($file->additions)->toBeNull()
        ->and($file->patch)->toBeNull();
});

it('marks an oversized file and keeps the rest', function () {
    $thread = Thread::factory()->create();
    fakeGit(
        ['M', 'big.lock', 'M', 'a.php', 'M', 'b.php', 'M', 'c.php'],
        ["9\t9\tbig.lock", "1\t0\ta.php", "1\t0\tb.php", "1\t0\tc.php"],
        ['big.lock' => str_repeat('x', 300 * 1024), 'a.php' => 'pa', 'b.php' => 'pb', 'c.php' => 'pc'],
    );

    $diff = captureFor($thread);
    $files = $diff->files->keyBy('path');

    expect($files['big.lock']->is_too_large)->toBeTrue()
        ->and($files['big.lock']->patch)->toBeNull()
        ->and($files['a.php']->patch)->toBe("pa\n")
        ->and($files['c.php']->patch)->toBe("pc\n")
        ->and($diff->is_truncated)->toBeFalse();
});

it('stops storing patches at the total cap', function () {
    config()->set('yak.threads.diff_max_total_bytes', 10);
    $thread = Thread::factory()->create();
    fakeGit(
        ['M', 'a.php', 'M', 'b.php'],
        ["1\t0\ta.php", "1\t0\tb.php"],
        ['a.php' => '123456', 'b.php' => '123456'],
    );

    $diff = captureFor($thread);
    $files = $diff->files->keyBy('path');

    expect($files['a.php']->patch)->toBe("123456\n")
        ->and($files['b.php']->is_too_large)->toBeTrue()
        ->and($files['b.php']->patch)->toBeNull()
        ->and($diff->is_truncated)->toBeTrue()
        ->and($diff->files_count)->toBe(2);
});

it('keeps the number of git commands bounded when a turn touches many files', function () {
    config()->set('yak.threads.diff_max_files', 2);
    $thread = Thread::factory()->create();
    $paths = array_map(fn (int $n): string => "f{$n}.php", range(1, 50));
    $commands = fakeGit(
        collect($paths)->flatMap(fn ($path) => ['M', $path])->all(),
        array_map(fn ($path) => "1\t0\t{$path}", $paths),
        array_fill_keys($paths, 'p'),
    );

    $diff = captureFor($thread);
    $patchCommands = collect($commands)->filter(fn ($command) => str_contains($command, 'head -c'));

    expect($patchCommands)->toHaveCount(2)
        ->and($diff->files_count)->toBe(50)
        ->and($diff->files->where('is_too_large', true))->toHaveCount(48)
        ->and($diff->is_truncated)->toBeTrue();
});

it('includes untracked files', function () {
    $thread = Thread::factory()->create();
    $commands = fakeGit(['A', 'untracked.php'], ["1\t0\tuntracked.php"], ['untracked.php' => 'p']);

    captureFor($thread);

    $setup = collect($commands)->first(fn ($command) => str_contains($command, 'merge-base'));
    expect($setup)->toContain('GIT_INDEX_FILE=')
        ->and($setup)->toContain('git add -A')
        ->and(collect($commands)->first(fn ($command) => str_contains($command, '--numstat')))->toContain('GIT_INDEX_FILE=')
        ->and(collect($commands)->last())->toStartWith('rm -f ');
});

it('keeps the previous diff when git fails', function () {
    $thread = Thread::factory()->create();
    fakeGit(['M', 'a.php'], ["1\t0\ta.php"], ['a.php' => 'p']);
    captureFor($thread);

    fakeGit([], [], fails: true);

    expect(captureFor($thread))->toBeNull()
        ->and(ThreadDiff::query()->where('thread_id', $thread->id)->first()->files)->toHaveCount(1);
});

it('replaces the previous diff with the new one', function () {
    $thread = Thread::factory()->create();
    fakeGit(['M', 'a.php'], ["1\t0\ta.php"], ['a.php' => 'p']);
    captureFor($thread);
    fakeGit(['M', 'b.php'], ["2\t0\tb.php"], ['b.php' => 'q']);
    captureFor($thread);

    $diff = ThreadDiff::query()->where('thread_id', $thread->id)->sole();
    expect($diff->files->pluck('path')->all())->toBe(['b.php']);
});

it('captures the diff when a finished turn pauses the sandbox', function () {
    Repository::factory()->create(['slug' => 'acme', 'default_branch' => 'develop']);
    $thread = Thread::factory()->create([
        'repo' => 'acme',
        'sandbox_container' => 'yak-thread-1',
        'sandbox_state' => ThreadSandboxState::Running,
    ]);
    $commands = fakeGit(['M', 'a.php'], ["1\t0\ta.php"], ['a.php' => 'p']);

    app(ThreadSandbox::class)->pause($thread, syncSessions: true);

    expect(ThreadDiff::query()->where('thread_id', $thread->id)->exists())->toBeTrue()
        ->and(collect($commands)->first())->toContain('origin/develop')
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
});
