<?php

use App\Channels\GitHub\AppService;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\IncusThreadSandboxManager;
use App\Services\Threads\Repos\ThreadGitHubCredentials;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);
});

function fakeTokenEndpoint(array $body, int $status = 201): void
{
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response($body, $status),
        'api.github.com/app/installations/42' => Http::response(['permissions' => ['contents' => 'write', 'metadata' => 'read', 'issues' => 'write']]),
    ]);
}

function scopedBody(array $overrides = []): array
{
    return [
        'token' => 'ghs_scoped',
        'repository_selection' => 'selected',
        'repositories' => [['name' => 'app'], ['name' => 'lib']],
        'permissions' => ['contents' => 'write', 'metadata' => 'read'],
        ...$overrides,
    ];
}

function tokenRequests(): array
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens'))->map(fn (array $pair): Request => $pair[0])->values()->all();
}

it('requests a token limited to the thread repositories and contents write', function () {
    fakeTokenEndpoint(scopedBody());

    $token = app(AppService::class)->getScopedInstallationToken(42, ['app', 'lib'], ['contents' => 'write', 'metadata' => 'read']);

    expect($token)->toBe('ghs_scoped');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request['repositories'] === ['app', 'lib']
        && $request['permissions'] === ['contents' => 'write', 'metadata' => 'read']);
});

it('throws when the response covers a repository that was not requested', function () {
    fakeTokenEndpoint(scopedBody(['repositories' => [['name' => 'app'], ['name' => 'secrets']]]));

    app(AppService::class)->getScopedInstallationToken(42, ['app'], ['contents' => 'write']);
})->throws(RuntimeException::class, 'not limited to the requested repositories');

it('throws when the response is installation wide', function () {
    fakeTokenEndpoint(scopedBody(['repository_selection' => 'all', 'repositories' => []]));

    app(AppService::class)->getScopedInstallationToken(42, ['app'], ['contents' => 'write']);
})->throws(RuntimeException::class, 'not limited to the requested repositories');

it('throws when the response grants wider permissions than requested', function () {
    fakeTokenEndpoint(scopedBody(['repositories' => [['name' => 'app']], 'permissions' => ['contents' => 'write', 'administration' => 'write']]));

    app(AppService::class)->getScopedInstallationToken(42, ['app'], ['contents' => 'write']);
})->throws(RuntimeException::class, 'wider permissions');

it('refuses an empty repository list without calling GitHub', function () {
    Http::fake();

    expect(fn () => app(AppService::class)->getScopedInstallationToken(42, [], ['contents' => 'write']))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('mints the gh token with read levels on the same repositories', function () {
    fakeTokenEndpoint(scopedBody(['token' => 'ghs_read', 'permissions' => ['contents' => 'read', 'metadata' => 'read']]));

    expect(app(AppService::class)->getReadOnlyInstallationToken(42, ['app', 'lib']))->toBe('ghs_read');

    $request = tokenRequests()[0];
    expect($request['repositories'])->toBe(['app', 'lib'])
        ->and(collect($request['permissions'])->unique()->values()->all())->toBe(['read']);
});

it('puts both tokens on the sandbox through stdin, context repos included', function () {
    $sandbox = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandbox);
    $thread = Thread::factory()->create(['repo' => 'acme/app']);
    $sandbox->create($thread);
    $sandbox->start($thread);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'acme/app']);
    ThreadRepository::factory()->context()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib']);

    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::sequence()
            ->push(scopedBody(['token' => 'ghs_git']))
            ->push(scopedBody(['token' => 'ghs_gh', 'permissions' => ['contents' => 'read', 'metadata' => 'read']])),
        'api.github.com/app/installations/42' => Http::response(['permissions' => ['contents' => 'write', 'metadata' => 'read']]),
    ]);

    app(ThreadGitHubCredentials::class)->install($thread);

    $requests = tokenRequests();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['repositories'])->toEqualCanonicalizing(['app', 'lib'])
        ->and($requests[0]['permissions'])->toBe(['contents' => 'write', 'metadata' => 'read'])
        ->and($requests[1]['repositories'])->toEqualCanonicalizing(['app', 'lib']);

    $execs = collect($sandbox->calls)->where('operation', 'exec');
    expect($execs->pluck('arguments.stdin')->filter()->values()->all())->toBe(['ghs_git', 'ghs_gh']);
    $execs->each(fn (array $call) => expect(implode(' ', $call['arguments']['command']))->not->toContain('ghs_'));
});

it('fails closed instead of minting a wide token when GitHub widens the scope', function () {
    $sandbox = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $sandbox);
    $thread = Thread::factory()->create(['repo' => 'acme/app']);
    $sandbox->create($thread);
    $sandbox->start($thread);
    fakeTokenEndpoint(scopedBody(['repository_selection' => 'all']));

    expect(fn () => app(ThreadGitHubCredentials::class)->install($thread))->toThrow(RuntimeException::class);
    expect(collect($sandbox->calls)->where('operation', 'exec'))->toBeEmpty();
});

it('does nothing without a configured installation', function () {
    config()->set('yak.channels.github.installation_id', 0);
    Http::fake();

    app(ThreadGitHubCredentials::class)->install(Thread::factory()->create());

    Http::assertNothingSent();
});

it('replaces sandbox tokens only once they may have expired', function () {
    $thread = Thread::factory()->create(['repo' => 'acme/app']);
    $sandboxes = Mockery::mock(IncusThreadSandboxManager::class);
    $sandboxes->shouldReceive('exec')->andReturn(Process::result());
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('getScopedInstallationToken')->twice()->andReturn('ghs_write');
    $github->shouldReceive('getReadOnlyInstallationToken')->twice()->andReturn('ghs_read');
    $credentials = new ThreadGitHubCredentials($github, $sandboxes);

    $credentials->ensureFresh($thread);
    $credentials->ensureFresh($thread);
    $this->travel(46)->minutes();
    $credentials->ensureFresh($thread);
});

it('never mints tokens for a sandbox that is not real', function () {
    $github = Mockery::mock(AppService::class);
    $github->shouldNotReceive('getScopedInstallationToken');

    (new ThreadGitHubCredentials($github, new FakeSandboxManager))->ensureFresh(Thread::factory()->create());
});
