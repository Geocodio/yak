<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\YakToolRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);

    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'repo' => 'acme/app', 'branch' => 'yak/thread-branch']);
    ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/app']);
});

function fakeReachableRepositories(array $reachable = ['acme/docs']): void
{
    $fakes = [
        'api.github.com/app/installations/42/access_tokens' => fn (Request $request) => Http::response([
            'token' => 'ghs_scoped',
            'expires_at' => now()->addHour()->toIso8601String(),
            'repository_selection' => 'selected',
            'repositories' => [['name' => 'app'], ['name' => 'docs']],
            'permissions' => $request->data()['permissions'] ?? ['contents' => 'read', 'metadata' => 'read'],
        ], 201),
        'api.github.com/app/installations/42' => Http::response(['permissions' => ['contents' => 'write', 'metadata' => 'read']]),
    ];

    foreach ($reachable as $slug) {
        $fakes["api.github.com/repos/{$slug}"] = Http::response(['description' => null, 'topics' => []]);
    }

    Http::fake([...$fakes, 'api.github.com/repos/*' => Http::response([], 404)]);
}

function addRepositoryCall(array $input): YakToolCall
{
    return new YakToolCall('call-1', 'add_repository', $input);
}

function queueSetup(string $cloneLog = '', int $cloneExit = 0): void
{
    $sandbox = app(SandboxManager::class);
    foreach (range(1, 4) as $ignored) {
        $sandbox->queueExecResult(Process::result());
    }
    $sandbox->queueExecResult(Process::result("@@YAK_BEGIN 0\n{$cloneLog}\n@@YAK_EXIT 0 {$cloneExit} 3\n"));
    $sandbox->queueExecResult(Process::result("@@YAK_INSPECT 0\n@@YAK_HEAD\nabc\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n"));
}

function repositoryCards(Thread $thread): array
{
    return DB::table('thread_events')->where('thread_id', $thread->id)->where('type', 'repository')->orderBy('sequence')->get()
        ->map(fn (object $row): array => json_decode($row->payload, true))->all();
}

it('adds a changes repository and returns its path', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    fakeReachableRepositories();
    queueSetup();

    $result = app(YakToolRouter::class)->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs', 'reason' => 'Needs the docs']));

    $row = $this->thread->repositories()->where('repo', 'acme/docs')->first();
    expect($result->isError)->toBeFalse()
        ->and($result->content)->toContain('/extras/docs')
        ->and($row->role)->toBe(RepositoryRole::Changes)
        ->and($row->added_by)->toBe('claude')
        ->and($row->add_reason)->toBe('Needs the docs')
        ->and($row->setup_status)->toBe(RepositorySetupStatus::Ready);
});

it('stores the context role', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    fakeReachableRepositories();
    queueSetup();

    app(YakToolRouter::class)->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs', 'role' => 'context']));

    expect($this->thread->repositories()->where('repo', 'acme/docs')->first()->role)->toBe(RepositoryRole::Context);
});

it('rejects the primary role and unknown roles', function () {
    $router = app(YakToolRouter::class);

    expect($router->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs', 'role' => 'primary']))->isError)->toBeTrue()
        ->and($router->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs', 'role' => 'bogus']))->isError)->toBeTrue()
        ->and($router->handle($this->thread, addRepositoryCall(['repo' => 'not a slug']))->isError)->toBeTrue()
        ->and($this->thread->repositories()->count())->toBe(1);
});

it('creates no row and reports no access for an unreachable repository', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    fakeReachableRepositories([]);

    $result = app(YakToolRouter::class)->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs']));

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toContain('Yak cannot access acme/docs.')
        ->and($this->thread->repositories()->count())->toBe(1)
        ->and(collect(app(SandboxManager::class)->calls)->where('operation', 'exec'))->toBeEmpty()
        ->and(repositoryCards($this->thread))->toHaveCount(1)
        ->and(repositoryCards($this->thread)[0]['state'])->toBe('no_access');
});

it('keeps a repository whose setup failed and tells Claude to stop and ask', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    fakeReachableRepositories();
    queueSetup('fatal: repository not found', 128);

    $result = app(YakToolRouter::class)->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs']));

    $row = $this->thread->repositories()->where('repo', 'acme/docs')->first();
    expect($result->isError)->toBeTrue()
        ->and($result->content)->toContain('Stop and ask the person how to proceed; do not continue in that repo.')
        ->and($row->setup_status)->toBe(RepositorySetupStatus::Failed);
});

it('returns the existing path for a duplicate without recloning', function () {
    ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/docs', 'sandbox_path' => '/extras/docs']);
    Http::fake();

    $result = app(YakToolRouter::class)->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs']));

    expect($result->isError)->toBeFalse()
        ->and($result->content)->toContain('/extras/docs')
        ->and($this->thread->repositories()->count())->toBe(2);
    Http::assertNothingSent();
    expect(collect(app(SandboxManager::class)->calls)->where('operation', 'exec'))->toBeEmpty();
});

it('passes other tools to the inner router', function () {
    $result = app(YakToolRouter::class)->handle($this->thread, new YakToolCall('c', 'list_threads', []));

    expect($result->isError)->toBeFalse();
});

it('mints scoped tokens that include the new repository', function () {
    Repository::factory()->create(['slug' => 'acme/docs']);
    fakeReachableRepositories();
    queueSetup();

    app(YakToolRouter::class)->handle($this->thread, addRepositoryCall(['repo' => 'acme/docs']));

    $scoped = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens') && isset($request->data()['repositories']))
        ->map(fn (array $pair): array => $pair[0]->data()['repositories'])->first();
    expect($scoped)->toEqualCanonicalizing(['app', 'docs']);
});
