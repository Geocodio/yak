<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\DiffFileStatus;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:14:00'));
    config()->set('yak.sandbox.workspace_path', '/workspace');
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
});

/**
 * @param  array<string, mixed>  $state
 * @return array{0: Thread, 1: User}
 */
function appFilesThread(array $state = []): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'repo' => 'example/api', 'sandbox_container' => 'yak-thread-7', 'sandbox_state' => ThreadSandboxState::Running, ...$state]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api']);

    return [$thread, $user];
}

/**
 * @param  array<string, string>|null  $headers
 */
function listAppFiles(Thread $thread, User $user, string $query = 'scope=repo', ?array $headers = null): TestResponse
{
    return test()->getJson(appThreadPath($thread, '/files?' . $query), $headers ?? phoneHeadersFor($user));
}

function shareAppFilesWith(Thread $thread, User $owner): array
{
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    return phoneHeadersFor($recipient);
}

it('lists the repository root with change markers', function () {
    [$thread, $user] = appFilesThread();
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 2]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'src/Importer.php', 'status' => DiffFileStatus::Modified]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'tests/ImporterTest.php', 'status' => DiffFileStatus::Added]);
    $this->sandboxes->queueExecResult(Process::result("d\t0\tsrc\0f\t1532\tREADME.md\0f\t310\tcomposer.json\0"));

    $response = listAppFiles($thread, $user)->assertOk();

    expect($response->json('entries.*.name'))->toBe(['src', 'composer.json', 'README.md'])
        ->and($response->json('root'))->toBe('/workspace')
        ->and($response->json('state'))->toBe('ready')
        ->and($response->json('changedPaths'))->toBe(['src/Importer.php', 'tests/ImporterTest.php'])
        ->and($response->json('addedPaths'))->toBe(['tests/ImporterTest.php'])
        ->and($response->json('truncated'))->toBeFalse();

    ContractFixture::assertMatches('app', 'thread-files', $response->json());
});

it('says the sandbox waits for a slot', function () {
    [$thread, $user] = appFilesThread(['sandbox_state' => ThreadSandboxState::Stopped]);
    $mock = Mockery::mock(ThreadSandbox::class);
    $mock->shouldReceive('ensureAwake')->andReturn(SandboxWake::waitingForSlot());
    app()->instance(ThreadSandbox::class, $mock);

    $response = listAppFiles($thread, $user)->assertStatus(409)->assertExactJson([
        'error' => 'sandbox_unavailable',
        'message' => 'The sandbox is waiting for a free slot.',
        'details' => ['state' => 'waking', 'canRestore' => false, 'waitingForSlot' => true, 'restoring' => false],
    ]);

    ContractFixture::assertMatches('app', 'error-sandbox-unavailable', $response->json());
});

it('answers each sandbox state with its sentence', function (string $case, string $message, array $details) {
    [$thread, $user] = appFilesThread();
    $headers = null;

    match ($case) {
        'asleep' => (function () use ($thread, $user, &$headers): void {
            $thread->update(['sandbox_state' => ThreadSandboxState::Paused]);
            $headers = shareAppFilesWith($thread, $user);
        })(),
        'destroyed' => $thread->update(['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null]),
        'closed' => $thread->update(['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null, 'status' => ThreadStatus::Closed, 'closed_at' => now()]),
        'on a computer' => $thread->update(['owner_side' => ThreadOwnerSide::Device]),
        'restoring' => $thread->update(['sandbox_state' => ThreadSandboxState::Starting]),
    };

    listAppFiles($thread, $user, headers: $headers)->assertStatus(409)->assertExactJson(['error' => 'sandbox_unavailable', 'message' => $message, 'details' => $details]);
    expect($this->sandboxes->calls)->toBe([]);
})->with([
    ['asleep', 'The sandbox is asleep. It wakes when someone who can drive this thread opens it.', ['state' => 'asleep', 'canRestore' => false, 'waitingForSlot' => false, 'restoring' => false]],
    ['destroyed', 'The sandbox was removed.', ['state' => 'destroyed', 'canRestore' => true, 'waitingForSlot' => false, 'restoring' => false]],
    ['closed', 'This thread is closed, so its sandbox is gone.', ['state' => 'closed_destroyed', 'canRestore' => false, 'waitingForSlot' => false, 'restoring' => false]],
    ['on a computer', 'This thread runs on a computer, so its files are there.', ['state' => 'on_device', 'canRestore' => false, 'waitingForSlot' => false, 'restoring' => false]],
    ['restoring', 'The sandbox is being restored.', ['state' => 'waking', 'canRestore' => false, 'waitingForSlot' => false, 'restoring' => true]],
]);

it('lets a share recipient list the repository but not the sandbox', function () {
    [$thread, $user] = appFilesThread();
    $headers = shareAppFilesWith($thread, $user);
    $this->sandboxes->queueExecResult(Process::result("f\t1\ta.txt\0"));

    listAppFiles($thread, $user, headers: $headers)->assertOk()->assertJsonPath('entries.0.name', 'a.txt');

    $calls = count($this->sandboxes->calls);
    listAppFiles($thread, $user, 'scope=sandbox', $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    expect($this->sandboxes->calls)->toHaveCount($calls);
});

it('lists a sandbox folder for the owner', function () {
    [$thread, $user] = appFilesThread();
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 1]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'src/Importer.php', 'status' => DiffFileStatus::Added]);
    $this->sandboxes->queueExecResult(Process::result("f\t5\tnotes.txt\0"));

    listAppFiles($thread, $user, 'scope=sandbox&path=/home/yak')->assertOk()
        ->assertJsonPath('root', '/home/yak')
        ->assertJsonPath('changedPaths', [])
        ->assertJsonPath('addedPaths', []);
});

it('answers not found for a missing folder', function () {
    [$thread, $user] = appFilesThread();
    $this->sandboxes->queueExecResult(Process::result('', '', 4));

    listAppFiles($thread, $user, 'scope=repo&path=missing')->assertNotFound()->assertJsonPath('error', 'not_found');
});

it('flags a cut folder', function () {
    config()->set('yak.files.tree_limit', 2);
    [$thread, $user] = appFilesThread();
    $this->sandboxes->queueExecResult(Process::result("f\t1\ta\0f\t1\tb\0f\t1\tc\0"));

    listAppFiles($thread, $user)->assertOk()->assertJsonPath('truncated', true)->assertJsonCount(2, 'entries');
});

it('validates the scope and path', function (string $query, string $field) {
    [$thread, $user] = appFilesThread();

    listAppFiles($thread, $user, $query)->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => [$field]]]);
    expect($this->sandboxes->calls)->toBe([]);
})->with([
    'unknown scope' => ['scope=repository', 'scope'],
    'missing scope' => ['path=src', 'scope'],
    'parent path' => ['scope=repo&path=../etc', 'path'],
    'null byte' => ['scope=repo&path=src%00x', 'path'],
]);

it('refuses a path longer than 1024 characters', function () {
    [$thread, $user] = appFilesThread();

    listAppFiles($thread, $user, 'scope=repo&path=' . str_repeat('a', 1025))->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['path']]]);
});

it('answers unavailable when the sandbox does not answer', function () {
    [$thread, $user] = appFilesThread();
    $this->sandboxes->queueExecResult(new SandboxCommandFailed('timed out'));

    listAppFiles($thread, $user)->assertStatus(503)->assertJsonPath('error', 'unavailable');
});

it('lists an empty folder', function () {
    [$thread, $user] = appFilesThread();

    listAppFiles($thread, $user)->assertOk()->assertJsonPath('entries', [])->assertJsonPath('truncated', false);
});

it('keeps the file routes off the device budget', function () {
    [$device] = phoneWithHeaders();
    $request = Request::create('/api/app/v1/threads/d0e515ef/files');
    $request->attributes->set('device', $device);

    expect(RateLimiter::limiter('app-device')($request))->toBeInstanceOf(Limit::class)
        ->and(RateLimiter::limiter('app-device')($request)->maxAttempts)->toBe(PHP_INT_MAX);

    $limit = RateLimiter::limiter('app-files')($request);
    expect($limit->maxAttempts)->toBe(1200)->and($limit->key)->toBe('files:' . $device->id)->and($limit->decaySeconds)->toBe(60);
});

it('answers a stranger like a missing thread', function () {
    [$thread] = appFilesThread();
    $stranger = User::factory()->create();
    $headers = phoneHeadersFor($stranger);

    $missing = test()->getJson('/api/app/v1/threads/zzzzzzzz/files?scope=repo', $headers)->assertNotFound()->json();

    listAppFiles($thread, $stranger, headers: $headers)->assertNotFound()->assertExactJson($missing);
    test()->getJson('/api/app/v1/threads/' . $thread->id . '/files?scope=repo', $headers)->assertNotFound()->assertExactJson($missing);
});

it('refuses a missing, revoked or computer token', function () {
    [$thread, $user] = appFilesThread();

    $this->getJson(appThreadPath($thread, '/files?scope=repo'), ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    [$device, $phoneHeaders] = phoneWithHeaders(['user_id' => $user->id]);
    $device->forceFill(['revoked_at' => now()])->save();
    listAppFiles($thread, $user, headers: $phoneHeaders)->assertUnauthorized();

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    listAppFiles($thread, $user, headers: $computerHeaders)->assertUnauthorized();
});
