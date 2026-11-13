<?php

use App\Enums\ThreadSandboxState;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\IncusSandboxManager;
use App\Services\Threads\IncusThreadSandboxManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

function incusManager(array $files = []): IncusThreadSandboxManager
{
    return new IncusThreadSandboxManager(app(IncusSandboxManager::class), fn (string $path): ?string => $files[$path] ?? null);
}

test('start writes the thread values the welcome splash reads, with control characters removed', function () {
    Process::fake(['*' => Process::result()]);
    $thread = Thread::factory()->create(['title' => "Fix it\nTITLE=evil \e[31mred", 'branch' => 'yak/fix-it']);

    incusManager()->start($thread);

    Process::assertRan(fn ($p) => is_array($p->command) && in_array('cat > ~/.yak-thread.env', $p->command, true)
        && $p->input === "TITLE=Fix it TITLE=evil  [31mred\nPUBLIC_ID={$thread->public_id}\nREPO={$thread->repo}\nBRANCH=yak/fix-it\nDOCS_URL=" . config('docs.base_url') . "ssh/\n");
});

test('create clones the template and leaves it stopped', function () {
    Process::fake();
    Repository::factory()->create(['slug' => 'widgets', 'sandbox_snapshot' => 'yak-tpl-widgets/v3']);
    $thread = Thread::factory()->create(['repo' => 'widgets']);

    incusManager()->create($thread);

    Process::assertRan(fn ($p) => implode(' ', $p->command) === "incus copy yak-tpl-widgets/v3 yak-thread-{$thread->id}");
    Process::assertNotRan(fn ($p) => str_contains(implode(' ', $p->command), 'incus start'));
    expect($thread->fresh()->sandbox_container)->toBe("yak-thread-{$thread->id}")
        ->and($thread->fresh()->sandbox_host)->toBe('local');
});

test('pause and resume use incus pause and resume', function () {
    Process::fake();
    $thread = Thread::factory()->create();

    incusManager()->pause($thread);
    incusManager()->resume($thread);

    Process::assertRan(fn ($p) => is_array($p->command) && implode(' ', $p->command) === "incus pause yak-thread-{$thread->id}");
    Process::assertRan(fn ($p) => is_array($p->command) && implode(' ', $p->command) === "incus resume yak-thread-{$thread->id}");
});

test('start waits until the container answers an exec', function () {
    Sleep::fake();
    Process::fake([
        '*start*' => Process::result(),
        '*exec*' => Process::sequence()->push(Process::result('', 'not ready', 1))->push(Process::result())->dontFailWhenEmpty(),
    ]);
    $thread = Thread::factory()->create();

    incusManager()->start($thread);

    Sleep::assertSleptTimes(1);
});

test('stop passes the timeout', function () {
    Process::fake();
    $thread = Thread::factory()->create();

    incusManager()->stop($thread, 45);

    Process::assertRan(fn ($p) => implode(' ', $p->command) === "incus stop yak-thread-{$thread->id} --timeout 45");
});

test('stop forces the container down when the graceful stop fails', function () {
    Process::fake([
        '*--timeout*' => Process::result('', 'timeout', 1),
        '*' => Process::result(),
    ]);
    $thread = Thread::factory()->create();

    incusManager()->stop($thread, 5);

    Process::assertRan(fn ($p) => implode(' ', $p->command) === "incus stop yak-thread-{$thread->id} --force");
});

test('destroy is idempotent when the container is gone', function () {
    Process::fake(['*' => Process::result('', 'Error: Instance not found', 1)]);
    $thread = Thread::factory()->create();

    incusManager()->destroy($thread);

    Process::assertRanTimes(fn ($p) => str_contains(implode(' ', $p->command), 'incus delete'), 1);
});

test('destroy surfaces other failures', function () {
    Process::fake(['*' => Process::result('', 'Error: storage busy', 1)]);

    expect(fn () => incusManager()->destroy(Thread::factory()->create()))->toThrow(SandboxCommandFailed::class);
});

test('exec passes env as arguments and stdin', function () {
    Process::fake();
    $thread = Thread::factory()->create();

    incusManager()->exec($thread, ['git', 'status'], 'payload', ['TOKEN' => 'abc']);

    Process::assertRan(fn ($p) => $p->command === ['incus', 'exec', "yak-thread-{$thread->id}", '--cwd', '/workspace', '--env', 'HOME=/home/yak', '--env', 'TOKEN=abc', '--', 'setpriv', '--reuid=1001', '--regid=1001', '--init-groups', '--', 'git', 'status']
        && $p->input === 'payload');
});

test('exec returns non-zero results but throws on transport failure without env values', function () {
    $thread = Thread::factory()->create();

    Process::fake(['*' => Process::result('', 'fatal: nope', 128)]);
    expect(incusManager()->exec($thread, ['git', 'x'])->exitCode())->toBe(128);

    Process::fake(['*' => Process::result('', 'Error: Instance is not running', 1)]);
    try {
        incusManager()->exec($thread, ['true'], null, ['TOKEN' => 'super-secret']);
        $this->fail('expected SandboxCommandFailed');
    } catch (SandboxCommandFailed $exception) {
        expect($exception->getMessage())->not->toContain('super-secret');
    }
});

test('available memory counts arc above minimum as free', function () {
    $meminfo = "MemTotal: 100 kB\nMemAvailable:   2048 kB\n";
    $arc = "size 4 5000\nc_min 4 3000\n";

    expect(incusManager(['/proc/meminfo' => $meminfo, '/proc/spl/kstat/zfs/arcstats' => $arc])->availableMemoryBytes())->toBe(2048 * 1024 + 2000);
    expect(incusManager(['/proc/meminfo' => $meminfo, '/proc/spl/kstat/zfs/arcstats' => "size 4 100\nc_min 4 3000\n"])->availableMemoryBytes())->toBe(2048 * 1024);
    expect(incusManager(['/proc/meminfo' => $meminfo])->availableMemoryBytes())->toBe(2048 * 1024);
});

test('pool used percent is cached for fifteen seconds', function () {
    Cache::flush();
    Process::fake(['*' => Process::result("Resources:\n  Space used: 1.00TiB\n  Total space: 4.00TiB\n")]);

    expect(incusManager()->poolUsedPercent())->toBe(25.0);
    expect(incusManager()->poolUsedPercent())->toBe(25.0);
    Process::assertRanTimes(fn ($p) => str_contains(implode(' ', $p->command), 'storage info'), 1);

    $this->travel(16)->seconds();
    incusManager()->poolUsedPercent();
    Process::assertRanTimes(fn ($p) => str_contains(implode(' ', $p->command), 'storage info'), 2);
});

test('state reads the row and never calls incus', function () {
    Process::fake();
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Paused]);

    expect(incusManager()->state($thread))->toBe(ThreadSandboxState::Paused);
    Process::assertNothingRan();
});

test('reconcile maps incus status to state', function (string $status, ThreadSandboxState $expected) {
    Process::fake(['*' => Process::result("Name: x\nStatus: {$status}\n")]);

    expect(incusManager()->reconcile(Thread::factory()->create()))->toBe($expected);
})->with([
    ['RUNNING', ThreadSandboxState::Running],
    ['FROZEN', ThreadSandboxState::Paused],
    ['STOPPED', ThreadSandboxState::Stopped],
]);

test('start records the sandbox address and destroy clears it', function () {
    Sleep::fake();
    Process::fake([
        '*incus list*' => Process::result("yak-thread-1,10.0.0.9 (eth0)\n172.17.0.1 (docker0)\n"),
        '*' => Process::result(),
    ]);
    $thread = Thread::factory()->create();

    incusManager()->start($thread);
    expect($thread->fresh()->sandbox_ip)->toBe('10.0.0.9');

    incusManager()->destroy($thread);
    expect($thread->fresh()->sandbox_ip)->toBeNull();
});

test('resume records the sandbox address', function () {
    Process::fake([
        '*incus list*' => Process::result("yak-thread-1,10.0.0.11 (eth0)\n"),
        '*' => Process::result(),
    ]);
    $thread = Thread::factory()->create();

    incusManager()->resume($thread);

    expect($thread->fresh()->sandbox_ip)->toBe('10.0.0.11');
});

test('stop clears the recorded sandbox address', function () {
    Process::fake();
    $thread = Thread::factory()->create(['sandbox_ip' => '10.0.0.9']);

    incusManager()->stop($thread, 30);

    expect($thread->fresh()->sandbox_ip)->toBeNull();
});

test('a missed address lookup clears the previous address', function () {
    Process::fake([
        '*incus list*' => Process::result(''),
        '*' => Process::result(),
    ]);
    $thread = Thread::factory()->create(['sandbox_ip' => '10.0.0.9']);

    incusManager()->resume($thread);

    expect($thread->fresh()->sandbox_ip)->toBeNull();
});
