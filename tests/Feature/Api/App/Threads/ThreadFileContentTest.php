<?php

use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
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
});

/**
 * @param  array<string, mixed>  $state
 * @return array{0: Thread, 1: User}
 */
function appFileThread(array $state = []): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'repo' => 'example/api', 'sandbox_container' => 'yak-thread-7', 'sandbox_state' => ThreadSandboxState::Running, ...$state]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api']);

    return [$thread, $user];
}

/**
 * @param  array<string, string>|null  $headers
 */
function readAppFile(Thread $thread, User $user, string $query = 'scope=repo&path=src/Importer.php', ?array $headers = null): TestResponse
{
    return test()->getJson(appThreadPath($thread, '/files/content?' . $query), $headers ?? phoneHeadersFor($user));
}

function shareAppFileWith(Thread $thread, User $owner): array
{
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    return phoneHeadersFor($recipient);
}

function queueAppFile($test, string $bytes, ?int $size = null, ?string $head = null): void
{
    $test->sandboxes->queueExecResult(Process::result(($size ?? strlen($bytes)) . "\n" . base64_encode($bytes) . "\n" . ($head === null ? '-' : base64_encode($head)) . "\n"));
}

it('returns a text file with its hash and committed version', function () {
    [$thread, $user] = appFileThread();
    $content = "<?php\n\nfinal class Importer {}\n";
    queueAppFile($this, $content, head: "<?php\n\nclass Importer {}\n");

    $response = readAppFile($thread, $user)->assertOk();

    expect($response->json('sha256'))->toBe(hash('sha256', $content))
        ->and($response->json('content'))->toBe($content)
        ->and($response->json('headContent'))->toBe("<?php\n\nclass Importer {}\n")
        ->and($response->json('language'))->toBe('php')
        ->and($response->json('state'))->toBe('ready')
        ->and($response->json('canEdit'))->toBeTrue();

    ContractFixture::assertMatches('app', 'thread-file', $response->json());
});

it('returns a binary file without content', function () {
    [$thread, $user] = appFileThread();
    queueAppFile($this, "PNG\0data");

    $response = readAppFile($thread, $user, 'scope=repo&path=public/logo.png')->assertOk();

    expect($response->json('isBinary'))->toBeTrue()
        ->and($response->json('content'))->toBeNull()
        ->and($response->json('headContent'))->toBeNull()
        ->and($response->json('canEdit'))->toBeFalse();

    ContractFixture::assertMatches('app', 'thread-file-binary', $response->json());
});

it('never returns a too-large file as text', function () {
    [$thread, $user] = appFileThread();
    queueAppFile($this, str_repeat('a', 1_000_001), 2_000_000);

    readAppFile($thread, $user, 'scope=repo&path=dump.sql')->assertOk()
        ->assertJsonPath('isTooLarge', true)
        ->assertJsonPath('content', null)
        ->assertJsonPath('canEdit', false);
});

it('lets a share recipient read but not edit', function () {
    [$thread, $user] = appFileThread();
    $headers = shareAppFileWith($thread, $user);
    queueAppFile($this, "hello\n");

    readAppFile($thread, $user, headers: $headers)->assertOk()->assertJsonPath('canEdit', false);

    $calls = count($this->sandboxes->calls);
    readAppFile($thread, $user, 'scope=sandbox&path=/home/yak/notes.txt', $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    expect($this->sandboxes->calls)->toHaveCount($calls);
});

it('reads a sandbox file for the owner', function () {
    [$thread, $user] = appFileThread();
    queueAppFile($this, "hello\n");

    readAppFile($thread, $user, 'scope=sandbox&path=/home/yak/notes.txt')->assertOk()->assertJsonPath('canEdit', true);
});

it('answers not found for a folder or a missing file', function () {
    [$thread, $user] = appFileThread();
    $this->sandboxes->queueExecResult(Process::result('', '', 4));

    readAppFile($thread, $user)->assertNotFound()->assertJsonPath('error', 'not_found');
});

it('needs a path', function () {
    [$thread, $user] = appFileThread();

    readAppFile($thread, $user, 'scope=repo')->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['details' => ['errors' => ['path']]]);
    expect($this->sandboxes->calls)->toBe([]);
});

it('refuses a parent path or a path longer than 1024 characters', function (string $query) {
    [$thread, $user] = appFileThread();

    readAppFile($thread, $user, $query)->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['path']]]);
})->with([
    'parent path' => ['scope=repo&path=../etc/passwd'],
    'too long' => [fn () => 'scope=repo&path=' . str_repeat('a', 1025)],
]);

it('answers the sandbox state for a share recipient on an asleep sandbox', function () {
    [$thread, $user] = appFileThread(['sandbox_state' => ThreadSandboxState::Paused]);
    $headers = shareAppFileWith($thread, $user);

    readAppFile($thread, $user, headers: $headers)->assertStatus(409)->assertExactJson([
        'error' => 'sandbox_unavailable',
        'message' => 'The sandbox is asleep. It wakes when someone who can drive this thread opens it.',
        'details' => ['state' => 'asleep', 'canRestore' => false, 'waitingForSlot' => false, 'restoring' => false],
    ]);
    expect($this->sandboxes->calls)->toBe([]);
});

it('answers unavailable when the sandbox does not answer', function () {
    [$thread, $user] = appFileThread();
    $this->sandboxes->queueExecResult(new SandboxCommandFailed('timed out'));

    readAppFile($thread, $user)->assertStatus(503)->assertJsonPath('error', 'unavailable');
});

it('answers a stranger like a missing thread', function () {
    [$thread] = appFileThread();
    $stranger = User::factory()->create();
    $headers = phoneHeadersFor($stranger);

    $missing = test()->getJson('/api/app/v1/threads/zzzzzzzz/files/content?scope=repo&path=README.md', $headers)->assertNotFound()->json();

    readAppFile($thread, $stranger, headers: $headers)->assertNotFound()->assertExactJson($missing);
    test()->getJson('/api/app/v1/threads/' . $thread->id . '/files/content?scope=repo&path=README.md', $headers)->assertNotFound()->assertExactJson($missing);
});

it('refuses a missing, revoked or computer token', function () {
    [$thread, $user] = appFileThread();

    $this->getJson(appThreadPath($thread, '/files/content?scope=repo&path=README.md'), ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    [$device, $phoneHeaders] = phoneWithHeaders(['user_id' => $user->id]);
    $device->forceFill(['revoked_at' => now()])->save();
    readAppFile($thread, $user, headers: $phoneHeaders)->assertUnauthorized();

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    readAppFile($thread, $user, headers: $computerHeaders)->assertUnauthorized();
});
