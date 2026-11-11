<?php

use App\DataTransferObjects\AppendOutcome;
use App\Enums\ThreadKind;
use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SegmentCipher;
use App\Services\SessionStore\SessionStore;
use App\Services\SessionStore\SessionSync;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\FilesystemSandboxManager;

beforeEach(function () {
    Sleep::fake();
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    $this->sandboxes = new FilesystemSandboxManager;
    $this->app->instance(SandboxManager::class, $this->sandboxes);
    $this->thread = Thread::factory()->create();
    $this->store = app(SessionStore::class);
    $this->sid = 'a0a0a0a0-0000-4000-8000-000000000001';
    $this->rel = $this->sid . '.jsonl';
    $this->dir = '/home/yak/.claude/projects/-workspace';
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

it('uploads only new bytes after each turn', function () {
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/{$this->rel}" => "one\n"];
    app(SessionSync::class)->syncFromSandbox($this->thread);

    $this->sandboxes->disks[$this->thread->id]["{$this->dir}/{$this->rel}"] .= "two\n";
    app(SessionSync::class)->syncFromSandbox($this->thread);

    $file = SessionFile::query()->firstOrFail();
    expect($this->store->read($this->thread, $this->sid, $this->rel))->toBe("one\ntwo\n")
        ->and($file->segments)->toHaveCount(2)
        ->and($file->checkpoint_sha256)->toBe(hash('sha256', "one\ntwo\n"));
});

it('uploads subagent files, meta files and tool results', function () {
    $this->sandboxes->disks[$this->thread->id] = [
        "{$this->dir}/{$this->rel}" => "a\n",
        "{$this->dir}/{$this->sid}/subagents/agent-x.jsonl" => "x\n",
        "{$this->dir}/{$this->sid}/subagents/agent-x.meta.json" => "{}\n",
        "{$this->dir}/{$this->sid}/tool-results/y.txt" => "out\n",
    ];
    app(SessionSync::class)->syncFromSandbox($this->thread);

    expect(SessionFile::query()->count())->toBe(4)
        ->and($this->store->read($this->thread, $this->sid, "{$this->sid}/subagents/agent-x.meta.json"))->toBe("{}\n")
        ->and($this->store->read($this->thread, $this->sid, "{$this->sid}/tool-results/y.txt"))->toBe("out\n");
});

it('uploads memory files under the memory session id', function () {
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/memory/MEMORY.md" => "- note\n"];
    app(SessionSync::class)->syncFromSandbox($this->thread);

    expect($this->store->read($this->thread, '_memory', 'memory/MEMORY.md'))->toBe("- note\n");
});

it('recovers once from a gap by re-reading from the stored length', function () {
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/{$this->rel}" => "one\ntwo\n"];
    $this->store->append($this->thread, $this->sid, $this->rel, 0, "one\n", hash('sha256', "one\n"), hash('sha256', ''));

    $calls = 0;
    $real = $this->store;
    $mock = Mockery::mock(SessionStore::class, [app(SegmentCipher::class)])->makePartial();
    $mock->shouldReceive('append')->andReturnUsing(function (...$arguments) use (&$calls, $real) {
        return ++$calls === 1 ? AppendOutcome::rejected('gap', 4) : $real->append(...$arguments);
    });
    $this->app->instance(SessionStore::class, $mock);

    app(SessionSync::class)->syncFromSandbox($this->thread);

    expect($calls)->toBe(2)
        ->and($real->read($this->thread, $this->sid, $this->rel))->toBe("one\ntwo\n");
});

it('gives up with a warning after repeated conflicts', function () {
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/{$this->rel}" => "one\n"];
    $mock = Mockery::mock(SessionStore::class, [app(SegmentCipher::class)])->makePartial();
    $mock->shouldReceive('append')->andReturn(AppendOutcome::rejected('gap', 0));
    $this->app->instance(SessionStore::class, $mock);
    Log::shouldReceive('channel')->with('yak')->andReturnSelf();
    Log::shouldReceive('warning')->atLeast()->once();

    app(SessionSync::class)->syncFromSandbox($this->thread);
});

it('uploads a large append-only file in bounded chunks', function () {
    $content = str_repeat(str_repeat('x', 1000) . "\n", 2600);
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/{$this->rel}" => $content];
    app(SessionSync::class)->syncFromSandbox($this->thread);

    $file = SessionFile::query()->firstOrFail();
    expect($this->store->read($this->thread, $this->sid, $this->rel))->toBe($content)
        ->and($file->segments->count())->toBeGreaterThanOrEqual(3)
        ->and($file->segments->max('length'))->toBeLessThanOrEqual(1048576);
});

it('leaves a half-written last line for the next sync', function () {
    $path = "{$this->dir}/{$this->rel}";
    $this->sandboxes->disks[$this->thread->id] = [$path => "one\ntw"];
    app(SessionSync::class)->syncFromSandbox($this->thread);
    expect($this->store->read($this->thread, $this->sid, $this->rel))->toBe("one\n");

    $this->sandboxes->disks[$this->thread->id][$path] = "one\ntwo\n";
    app(SessionSync::class)->syncFromSandbox($this->thread);
    expect($this->store->read($this->thread, $this->sid, $this->rel))->toBe("one\ntwo\n");
});

it('waits for the files to stop growing before reading', function () {
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/{$this->rel}" => "one\n"];
    app(SessionSync::class)->syncFromSandbox($this->thread);

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 500.0);
});

it('ignores files outside the session layout', function () {
    $this->sandboxes->disks[$this->thread->id] = [
        "{$this->dir}/notes.bin" => 'x',
        "{$this->dir}/{$this->sid}/notes.bin" => 'x',
        "{$this->dir}/not-a-uuid.jsonl" => "x\n",
    ];
    app(SessionSync::class)->syncFromSandbox($this->thread);

    expect(SessionFile::query()->count())->toBe(0);
});

it('throws when reading a sandbox file fails', function () {
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/{$this->rel}" => "one\n"];
    $this->sandboxes->failures['tail -c'] = 'disk gone';

    app(SessionSync::class)->syncFromSandbox($this->thread);
})->throws(RuntimeException::class, 'disk gone');

it('syncs a research child transcript written under its worktree project directory', function () {
    $parent = Thread::factory()->create(['sandbox_container' => 'yak-shared']);
    $child = Thread::factory()->child($parent)->create(['kind' => ThreadKind::Research, 'name' => 'scan', 'sandbox_container' => 'yak-shared', 'claude_config_dir' => '/home/yak/.claude-child']);
    $this->sandboxes->disks[$child->id] = ["/home/yak/.claude-child/projects/-workspace-research-scan/{$this->rel}" => "one\n"];

    app(SessionSync::class)->syncFromSandbox($child);

    expect($this->store->length($child, $this->sid, $this->rel))->toBe(4);
});

it('keeps the root thread on the -workspace project directory', function () {
    $this->sandboxes->disks[$this->thread->id] = ["{$this->dir}/{$this->rel}" => "one\n"];

    app(SessionSync::class)->syncFromSandbox($this->thread);

    expect($this->store->length($this->thread, $this->sid, $this->rel))->toBe(4);
});
