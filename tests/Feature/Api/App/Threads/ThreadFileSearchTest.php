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
 * @return array{0: Thread, 1: User}
 */
function appSearchThread(): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'repo' => 'example/api', 'sandbox_container' => 'yak-thread-7', 'sandbox_state' => ThreadSandboxState::Running]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api']);

    return [$thread, $user];
}

/**
 * @param  array<string, string>|null  $headers
 */
function searchAppFiles(Thread $thread, User $user, string $query = 'scope=repo&q=importer', ?array $headers = null): TestResponse
{
    return test()->getJson(appThreadPath($thread, '/files/search?' . $query), $headers ?? phoneHeadersFor($user));
}

it('finds files by path', function () {
    [$thread, $user] = appSearchThread();
    $this->sandboxes->queueExecResult(Process::result("src/Importer.php\0"));

    $response = searchAppFiles($thread, $user)->assertOk()->assertExactJson([
        'state' => 'ready',
        'matches' => [['name' => 'Importer.php', 'path' => 'src/Importer.php']],
        'truncated' => false,
    ]);

    ContractFixture::assertMatches('app', 'thread-files-search', $response->json());
});

it('caps matches at 100', function () {
    [$thread, $user] = appSearchThread();
    $this->sandboxes->queueExecResult(Process::result(implode('', array_map(fn (int $number): string => "src/file-{$number}.php\0", range(1, 101)))));

    searchAppFiles($thread, $user)->assertOk()->assertJsonCount(100, 'matches')->assertJsonPath('truncated', true);
});

it('passes the query as one escaped fixed string', function () {
    [$thread, $user] = appSearchThread();

    searchAppFiles($thread, $user, 'scope=repo&q=' . urlencode("a'\$(x)"))->assertOk();

    $command = collect($this->sandboxes->calls)->last()['arguments']['command'][2];
    expect($command)->toContain('grep -z -i -F -e ' . escapeshellarg("a'\$(x)"))
        ->and($command)->toContain('head -z -n 101')
        ->and($command)->toContain('${PIPESTATUS[@]}')
        ->and($command)->toContain('case ${codes[0]} in 0|141) ;; *) exit 2;; esac');
});

it('finds nothing without an error', function () {
    [$thread, $user] = appSearchThread();
    $this->sandboxes->queueExecResult(Process::result('', '', 0));

    searchAppFiles($thread, $user)->assertOk()->assertExactJson(['state' => 'ready', 'matches' => [], 'truncated' => false]);
});

it('treats a closed pipe as a normal end', function () {
    [$thread, $user] = appSearchThread();
    $this->sandboxes->queueExecResult(Process::result("a.php\0", '', 0));

    searchAppFiles($thread, $user)->assertOk()->assertJsonPath('matches.0.path', 'a.php');
});

it('searches only the repository', function () {
    [$thread, $user] = appSearchThread();

    searchAppFiles($thread, $user, 'scope=sandbox&q=a')->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['scope']]]);
    expect($this->sandboxes->calls)->toBe([]);
});

it('validates the query', function (string $query) {
    [$thread, $user] = appSearchThread();

    searchAppFiles($thread, $user, 'scope=repo&q=' . $query)->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['q']]]);
    expect($this->sandboxes->calls)->toBe([]);
})->with([
    'empty' => [''],
    'too long' => [str_repeat('a', 201)],
    'a tab' => ['a%09b'],
]);

it('lets a share recipient search', function () {
    [$thread, $user] = appSearchThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $user, ThreadShareMode::People, [$recipient->id]);
    $this->sandboxes->queueExecResult(Process::result("README.md\0"));

    searchAppFiles($thread, $user, 'scope=repo&q=readme', phoneHeadersFor($recipient))->assertOk()->assertJsonPath('matches.0.name', 'README.md');
});

it('answers each sandbox state without running a command', function (ThreadSandboxState $state, ?string $container, string $reported) {
    [$thread, $user] = appSearchThread();
    $thread->update(['sandbox_state' => $state, 'sandbox_container' => $container]);

    searchAppFiles($thread, $user)->assertStatus(409)->assertJsonPath('error', 'sandbox_unavailable')->assertJsonPath('details.state', $reported);
    expect($this->sandboxes->calls)->toBe([]);
})->with([
    'none' => [ThreadSandboxState::None, null, 'destroyed'],
    'destroyed' => [ThreadSandboxState::Destroyed, null, 'destroyed'],
]);

it('refuses a share recipient on an asleep sandbox without waking it', function () {
    [$thread, $user] = appSearchThread();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $user, ThreadShareMode::People, [$recipient->id]);
    $thread->update(['sandbox_state' => ThreadSandboxState::Paused]);

    searchAppFiles($thread, $user, 'scope=repo&q=a', phoneHeadersFor($recipient))->assertStatus(409)->assertJsonPath('details.state', 'asleep');
    expect($this->sandboxes->calls)->toBe([]);
});

it('answers the repository guard refusals', function (int $exitCode, int $status) {
    [$thread, $user] = appSearchThread();
    $this->sandboxes->queueExecResult(Process::result('', '', $exitCode));

    searchAppFiles($thread, $user)->assertStatus($status);
})->with([
    'outside the repository' => [3, 422],
    'no repository root' => [4, 404],
]);

it('answers unavailable when the search fails or the sandbox does not answer', function () {
    [$thread, $user] = appSearchThread();
    $this->sandboxes->queueExecResult(Process::result('', 'fatal', 2));
    searchAppFiles($thread, $user)->assertStatus(503)->assertJsonPath('error', 'unavailable');

    $this->sandboxes->queueExecResult(new SandboxCommandFailed('timed out'));
    searchAppFiles($thread, $user)->assertStatus(503)->assertJsonPath('error', 'unavailable');
});

it('answers a stranger like a missing thread', function () {
    [$thread] = appSearchThread();
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = test()->getJson('/api/app/v1/threads/zzzzzzzz/files/search?scope=repo&q=a', $headers)->assertNotFound()->json();

    test()->getJson(appThreadPath($thread, '/files/search?scope=repo&q=a'), $headers)->assertNotFound()->assertExactJson($missing);
    test()->getJson('/api/app/v1/threads/' . $thread->id . '/files/search?scope=repo&q=a', $headers)->assertNotFound()->assertExactJson($missing);
});

it('refuses a missing, revoked or computer token', function () {
    [$thread, $user] = appSearchThread();

    $this->getJson(appThreadPath($thread, '/files/search?scope=repo&q=a'), ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    [$device, $phoneHeaders] = phoneWithHeaders(['user_id' => $user->id]);
    $device->forceFill(['revoked_at' => now()])->save();
    searchAppFiles($thread, $user, headers: $phoneHeaders)->assertUnauthorized();

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    searchAppFiles($thread, $user, headers: $computerHeaders)->assertUnauthorized();
});
