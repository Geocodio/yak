<?php

use App\DataTransferObjects\AppendOutcome;
use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Repository;
use App\Models\SessionFile;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\SessionStore\SessionStore;
use App\Services\SessionStore\SessionSync;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    Sleep::fake();
    $this->app->instance(ThreadStreams::class, new FakeThreadStreams);
    $this->root = sys_get_temp_dir() . '/session-sync-' . uniqid();
    $this->legacy = sys_get_temp_dir() . '/session-legacy-' . uniqid();
    File::ensureDirectoryExists($this->legacy);
    config()->set('yak.threads.session_store_path', $this->root);
    config()->set('yak.sandbox.session_transcript_path', $this->legacy);
    $this->store = app(SessionStore::class);
    $this->thread = Thread::factory()->create([
        'sandbox_container' => 'yak-thread-1',
        'sandbox_state' => ThreadSandboxState::Running,
        'runner_state' => RunnerState::None,
    ]);
    $this->files = [];
    $this->conflicts = 0;
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory($this->legacy);
});

/**
 * Fakes a sandbox whose files are the given path => bytes map.
 */
function fakeSandboxFiles(array &$files): void
{
    Process::fake(function ($process) use (&$files) {
        $command = (string) $process->command;

        if (str_contains($command, 'find ')) {
            $lines = '';
            foreach ($files as $path => $bytes) {
                $lines .= strlen($bytes) . " {$path}\n";
            }

            return Process::result(output: $lines);
        }

        foreach ($files as $path => $bytes) {
            if (! str_contains($command, $path)) {
                continue;
            }
            if (preg_match('/tail -c \+(\d+)/', $command, $matches)) {
                return Process::result(output: substr($bytes, (int) $matches[1] - 1));
            }
            if (str_contains($command, 'sha256sum')) {
                return Process::result(output: hash('sha256', $bytes) . "  {$path}\n");
            }
        }

        return Process::result();
    });
}

it('uploads only new bytes after each turn', function () {
    $first = "{\"uuid\":\"a\"}\n";
    $second = "{\"uuid\":\"b\",\"parentUuid\":\"a\"}\n";
    $this->files = ['0a1b2c3d-1111-4222-8333-444455556666.jsonl' => $first];
    fakeSandboxFiles($this->files);

    app(SessionSync::class)->syncFromSandbox($this->thread, 'yak-thread-1');
    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl'))->toBe($first);

    $this->files['0a1b2c3d-1111-4222-8333-444455556666.jsonl'] .= $second;
    app(SessionSync::class)->syncFromSandbox($this->thread, 'yak-thread-1');

    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl'))->toBe($first . $second)
        ->and(SessionFile::query()->firstOrFail()->checkpoint_sha256)->toBe(hash('sha256', $first . $second))
        ->and(SessionFile::query()->firstOrFail()->segments)->toHaveCount(2);
});

it('uploads subagent files, meta files and tool results', function () {
    $this->files = [
        '0a1b2c3d-1111-4222-8333-444455556666.jsonl' => "{\"uuid\":\"a\"}\n",
        '0a1b2c3d-1111-4222-8333-444455556666/subagents/agent-x.jsonl' => "{\"uuid\":\"x\"}\n",
        '0a1b2c3d-1111-4222-8333-444455556666/subagents/agent-x.meta.json' => "{\"type\":\"explore\"}\n",
        '0a1b2c3d-1111-4222-8333-444455556666/tool-results/y.txt' => "big output\n",
        '0a1b2c3d-1111-4222-8333-444455556666/notes.bin' => 'ignored',
    ];
    fakeSandboxFiles($this->files);

    app(SessionSync::class)->syncFromSandbox($this->thread, 'yak-thread-1');

    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/subagents/agent-x.jsonl'))->toBe("{\"uuid\":\"x\"}\n")
        ->and($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/subagents/agent-x.meta.json'))->toBe("{\"type\":\"explore\"}\n")
        ->and($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/tool-results/y.txt'))->toBe("big output\n")
        ->and(SessionFile::query()->count())->toBe(4);
});

it('recovers once from a gap by re-reading from the stored length', function () {
    $line = "{\"uuid\":\"a\"}\n";
    $this->files = ['0a1b2c3d-1111-4222-8333-444455556666.jsonl' => $line . "{\"uuid\":\"b\"}\n"];
    fakeSandboxFiles($this->files);
    $this->store->append($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl', 0, $line, hash('sha256', $line), hash('sha256', ''));

    $calls = 0;
    $real = $this->store;
    $mock = Mockery::mock(SessionStore::class)->makePartial();
    $mock->shouldReceive('append')->andReturnUsing(function (...$arguments) use (&$calls, $real) {
        return ++$calls === 1 ? AppendOutcome::rejected('gap', strlen("{\"uuid\":\"a\"}\n")) : $real->append(...$arguments);
    });
    app()->instance(SessionStore::class, $mock);

    app(SessionSync::class)->syncFromSandbox($this->thread, 'yak-thread-1');

    expect($calls)->toBe(2);
    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl'))->toBe($this->files['0a1b2c3d-1111-4222-8333-444455556666.jsonl']);
});

it('syncs before a stop and before a destroy', function () {
    $this->files = ['0a1b2c3d-1111-4222-8333-444455556666.jsonl' => "{\"uuid\":\"a\"}\n"];
    fakeSandboxFiles($this->files);
    $this->thread->forceFill(['sandbox_state' => ThreadSandboxState::Paused])->save();

    app(ThreadSandbox::class)->stop($this->thread->fresh());
    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl'))->toBe($this->files['0a1b2c3d-1111-4222-8333-444455556666.jsonl']);

    $this->files['0a1b2c3d-1111-4222-8333-444455556666.jsonl'] .= "{\"uuid\":\"b\"}\n";
    $this->thread->refresh()->forceFill(['sandbox_state' => ThreadSandboxState::Stopped])->save();
    app(ThreadSandbox::class)->destroy($this->thread->fresh());

    expect($this->store->read($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl'))->toBe($this->files['0a1b2c3d-1111-4222-8333-444455556666.jsonl']);
});

it('restores every session file into a fresh sandbox', function () {
    $line = "{\"uuid\":\"a\"}\n";
    $this->store->append($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl', 0, $line, hash('sha256', $line), hash('sha256', ''));
    $this->store->putWhole($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/subagents/a.meta.json', "{}\n");
    $this->store->putImmutable($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666/tool-results/y.txt', "out\n");
    Process::fake();

    app(SessionSync::class)->restoreIntoSandbox($this->thread, 'yak-thread-1');

    $directory = '/home/yak/.claude/projects/-workspace';
    foreach (['0a1b2c3d-1111-4222-8333-444455556666.jsonl' => $line, '0a1b2c3d-1111-4222-8333-444455556666/subagents/a.meta.json' => "{}\n", '0a1b2c3d-1111-4222-8333-444455556666/tool-results/y.txt' => "out\n"] as $path => $content) {
        Process::assertRan(fn ($process) => str_contains((string) $process->command, "{$directory}/{$path}") && $process->input === $content);
    }
    Process::assertRan(fn ($process) => str_contains((string) $process->command, 'chown -R yak:yak'));
});

it('restores into a sandbox created for a destroyed thread', function () {
    $line = "{\"uuid\":\"a\"}\n";
    $this->store->append($this->thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl', 0, $line, hash('sha256', $line), hash('sha256', ''));
    $this->thread->forceFill(['sandbox_state' => ThreadSandboxState::Destroyed])->save();
    Process::fake([
        'incus info *' => Process::result(exitCode: 1),
        '*systemctl is-system-running*' => Process::result(output: 'running'),
        'incus list *' => Process::result(output: 'RUNNING'),
        '*' => Process::result(),
    ]);
    $source = sys_get_temp_dir() . '/yak-sync-config-' . uniqid();
    mkdir($source, 0777, true);
    config()->set('yak.sandbox.claude_config_source', $source);

    app(ThreadSandbox::class)->ensureAwake($this->thread, Repository::factory()->create());

    Process::assertRan(fn ($process) => str_contains((string) $process->command, '/projects/-workspace/0a1b2c3d-1111-4222-8333-444455556666.jsonl') && $process->input === $line);
    File::deleteDirectory($source);
});

it('seeds a thread from the legacy host transcript', function () {
    $line = "{\"uuid\":\"a\"}\n";
    File::put("{$this->legacy}/0a1b2c3d-1111-4222-8333-555555556666.jsonl", $line);
    $task = YakTask::factory()->create(['session_id' => '0a1b2c3d-1111-4222-8333-555555556666']);

    $thread = app(ThreadFactory::class)->forTask($task);

    expect($this->store->read($thread->id, '0a1b2c3d-1111-4222-8333-555555556666', '0a1b2c3d-1111-4222-8333-555555556666.jsonl'))->toBe($line);

    // Importing again changes nothing.
    expect(app(SessionSync::class)->seedFromLegacyTranscript($thread, '0a1b2c3d-1111-4222-8333-555555556666'))->toBeTrue()
        ->and(SessionFile::query()->where('thread_id', $thread->id)->firstOrFail()->length)->toBe(strlen($line));
});

it('falls back when the legacy transcript is missing', function () {
    expect(app(SessionSync::class)->seedFromLegacyTranscript($this->thread, 'gone'))->toBeFalse()
        ->and(SessionFile::query()->count())->toBe(0);
});

it('deletes session files after the retention window', function () {
    config()->set('yak.threads.session_retention_days', 30);
    $line = "{\"uuid\":\"a\"}\n";
    $old = Thread::factory()->create(['status' => ThreadStatus::Closed, 'closed_at' => now()->subDays(31)]);
    $recent = Thread::factory()->create(['status' => ThreadStatus::Closed, 'closed_at' => now()->subDays(5)]);
    foreach ([$old, $recent] as $thread) {
        $this->store->append($thread->id, '0a1b2c3d-1111-4222-8333-444455556666', '0a1b2c3d-1111-4222-8333-444455556666.jsonl', 0, $line, hash('sha256', $line), hash('sha256', ''));
    }

    $this->artisan('yak:thread-sandboxes')->assertSuccessful();

    expect(SessionFile::query()->where('thread_id', $old->id)->exists())->toBeFalse()
        ->and(SessionFile::query()->where('thread_id', $recent->id)->exists())->toBeTrue();
});
