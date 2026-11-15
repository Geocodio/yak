<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadFileEdit;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:14:00'));
    config()->set('yak.sandbox.workspace_path', '/workspace');
    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);
    $delivery = Mockery::mock(SteeringDelivery::class);
    $delivery->shouldReceive('deliver')->andReturn(true);
    app()->instance(SteeringDelivery::class, $delivery);
});

/**
 * @param  array<string, mixed>  $state
 * @return array{0: Thread, 1: User}
 */
function saveAppThread(array $state = []): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'repo' => 'example/api', 'sandbox_container' => 'yak-thread-7', 'sandbox_state' => ThreadSandboxState::Running, ...$state]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api']);
    YakTask::factory()->create(['thread_id' => $thread->id]);

    return [$thread, $user];
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<string, string>|null  $headers
 */
function saveAppFile(Thread $thread, User $user, array $overrides = [], ?array $headers = null): TestResponse
{
    $payload = ['scope' => 'repo', 'path' => 'src/Importer.php', 'content' => "<?php\n\nfinal class Importer {}\n", 'base_sha256' => hash('sha256', "<?php\n\nclass Importer {}\n"), ...$overrides];

    return test()->putJson(appThreadPath($thread, '/files/content'), $payload, $headers ?? phoneHeadersFor($user));
}

function queueSaveRead($test, string $bytes): void
{
    $test->sandboxes->queueExecResult(Process::result(strlen($bytes) . "\n" . base64_encode($bytes) . "\n-\n"));
}

function savedWriteCount($test): int
{
    return count(array_filter($test->sandboxes->calls, fn (array $call): bool => str_contains((string) ($call['arguments']['command'][2] ?? ''), 'base64 -d')));
}

it('saves a file and tells Claude once', function () {
    [$thread, $user] = saveAppThread();
    $old = "<?php\n\nclass Importer {}\n";
    $new = "<?php\n\nfinal class Importer {}\n";
    queueSaveRead($this, $old);
    $this->sandboxes->queueExecResult(Process::result());
    queueSaveRead($this, $new);

    $response = saveAppFile($thread, $user)->assertOk();

    expect($response->json('state'))->toBe('saved')
        ->and($response->json('file.sha256'))->toBe(hash('sha256', $new))
        ->and($response->json('working'))->toBeFalse()
        ->and(ThreadFileEdit::query()->count())->toBe(1);

    ContractFixture::assertMatches('app', 'thread-file-saved', $response->json());
});

it('answers a conflict with the file on disk', function () {
    [$thread, $user] = saveAppThread();
    queueSaveRead($this, "<?php\n\nclass Importer { /* changed */ }\n");

    $response = saveAppFile($thread, $user)->assertStatus(409);

    expect($response->json('error'))->toBe('file_conflict')
        ->and($response->json('message'))->toBe('The file changed since you opened it.')
        ->and($response->json('details.state'))->toBe('conflict')
        ->and(savedWriteCount($this))->toBe(0)
        ->and(ThreadFileEdit::query()->count())->toBe(0);

    ContractFixture::assertMatches('app', 'error-file-conflict', $response->json());
});

it('recognises its own save after a lost response', function () {
    [$thread, $user] = saveAppThread();
    $new = "<?php\n\nfinal class Importer {}\n";
    queueSaveRead($this, "<?php\n\nclass Importer {}\n");
    $this->sandboxes->queueExecResult(Process::result());
    queueSaveRead($this, $new);
    saveAppFile($thread, $user)->assertOk();

    queueSaveRead($this, $new);
    saveAppFile($thread, $user)->assertStatus(409)
        ->assertJsonPath('error', 'file_conflict')
        ->assertJsonPath('details.file.sha256', hash('sha256', $new));

    expect(savedWriteCount($this))->toBe(1)->and(ThreadFileEdit::query()->count())->toBe(1);
});

it('does not write twice when an overwrite is repeated', function () {
    [$thread, $user] = saveAppThread();
    $new = "<?php\n\nfinal class Importer {}\n";
    queueSaveRead($this, 'something else');
    $this->sandboxes->queueExecResult(Process::result());
    queueSaveRead($this, $new);
    saveAppFile($thread, $user, ['overwrite' => true])->assertOk();

    queueSaveRead($this, $new);
    saveAppFile($thread, $user, ['overwrite' => true])->assertOk()->assertJsonPath('state', 'saved')->assertJsonPath('file.sha256', hash('sha256', $new));

    expect(savedWriteCount($this))->toBe(1)->and(ThreadFileEdit::query()->count())->toBe(1);
});

it('says the file was deleted meanwhile', function () {
    [$thread, $user] = saveAppThread();
    $this->sandboxes->queueExecResult(Process::result('', '', 4));

    saveAppFile($thread, $user)->assertStatus(409)->assertJsonPath('error', 'file_conflict')->assertJsonPath('details.file', null);
});

it('creates a new file with an empty base', function () {
    [$thread, $user] = saveAppThread();
    $this->sandboxes->queueExecResult(Process::result('', '', 4));
    $this->sandboxes->queueExecResult(Process::result());
    queueSaveRead($this, 'hello');

    saveAppFile($thread, $user, ['path' => 'tests/ImporterTest.php', 'content' => 'hello', 'base_sha256' => ''])->assertOk()->assertJsonPath('state', 'saved');
});

it('refuses a save into a closed thread', function () {
    [$thread, $user] = saveAppThread();
    $thread->update(['status' => ThreadStatus::Closed, 'closed_at' => now()]);

    saveAppFile($thread, $user)->assertStatus(409)->assertExactJson(['error' => 'thread_closed', 'message' => 'This thread is closed.']);

    expect($this->sandboxes->calls)->toBe([])->and(ThreadFileEdit::query()->count())->toBe(0);
});

it('refuses a share recipient', function () {
    [$thread, $user] = saveAppThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $user, ThreadShareMode::People, [$recipient->id]);

    saveAppFile($thread, $user, headers: phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect($this->sandboxes->calls)->toBe([]);
});

it('validates the save', function (array $overrides, string $field) {
    [$thread, $user] = saveAppThread();

    saveAppFile($thread, $user, $overrides)->assertStatus(422)->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([
    'content over the byte limit' => [fn () => ['content' => str_repeat('é', (int) ceil((int) config('yak.files.max_edit_bytes') / 2) + 1)], 'content'],
    'bad hash' => [['base_sha256' => 'xyz'], 'base_sha256'],
    'missing path' => [['path' => ''], 'path'],
]);

it('refuses a read-only folder and a binary file', function () {
    [$thread, $user] = saveAppThread();

    saveAppFile($thread, $user, ['scope' => 'sandbox', 'path' => '/usr/bin/x'])->assertStatus(422)->assertJsonPath('details.errors.path.0', 'This folder is read-only.');

    queueSaveRead($this, "PNG\0data");
    saveAppFile($thread, $user, ['path' => 'public/logo.png'])->assertStatus(422)->assertJsonPath('details.errors.path.0', 'The file cannot be edited as text.');
});

it('answers unavailable when the write fails', function () {
    [$thread, $user] = saveAppThread();
    queueSaveRead($this, "<?php\n\nclass Importer {}\n");
    $this->sandboxes->queueExecResult(Process::result('', '', 1));

    saveAppFile($thread, $user)->assertStatus(503)->assertJsonPath('error', 'unavailable');

    expect(ThreadFileEdit::query()->count())->toBe(0);
});

it('says Claude is working', function () {
    [$thread, $user] = saveAppThread(['runner_state' => RunnerState::Busy]);
    queueSaveRead($this, "<?php\n\nclass Importer {}\n");
    $this->sandboxes->queueExecResult(Process::result());
    queueSaveRead($this, 'new');

    saveAppFile($thread, $user)->assertOk()->assertJsonPath('working', true);
});

it('answers the sandbox state when the sandbox is gone', function (array $state, string $detailState) {
    [$thread, $user] = saveAppThread($state);

    saveAppFile($thread, $user)->assertStatus(409)->assertJsonPath('error', 'sandbox_unavailable')->assertJsonPath('details.state', $detailState);

    expect($this->sandboxes->calls)->toBe([]);
})->with([
    'destroyed' => [['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null], 'destroyed'],
    'closed' => [['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null, 'status' => ThreadStatus::Closed, 'closed_at' => '2026-11-16 09:00:00'], 'closed_destroyed'],
]);

it('answers a stranger like a missing thread', function () {
    [$thread] = saveAppThread();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = test()->putJson('/api/app/v1/threads/zzzzzzzz/files/content', ['scope' => 'repo', 'path' => 'a', 'content' => 'x', 'base_sha256' => ''], $headers)->assertNotFound()->json();

    test()->putJson(appThreadPath($thread, '/files/content'), ['scope' => 'repo', 'path' => 'a', 'content' => 'x', 'base_sha256' => ''], $headers)->assertNotFound()->assertExactJson($missing);
    test()->putJson('/api/app/v1/threads/' . $thread->id . '/files/content', ['scope' => 'repo', 'path' => 'a', 'content' => 'x', 'base_sha256' => ''], $headers)->assertNotFound()->assertExactJson($missing);
});

it('refuses a missing, revoked or computer token', function () {
    [$thread, $user] = saveAppThread();

    $this->putJson(appThreadPath($thread, '/files/content'), [], ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    [$device, $phoneHeaders] = phoneWithHeaders(['user_id' => $user->id]);
    $device->forceFill(['revoked_at' => now()])->save();
    saveAppFile($thread, $user, headers: $phoneHeaders)->assertUnauthorized();

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    saveAppFile($thread, $user, headers: $computerHeaders)->assertUnauthorized();
});
