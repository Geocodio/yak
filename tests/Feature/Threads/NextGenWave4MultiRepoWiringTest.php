<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\TaskMode;
use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Services\Datasets\DatasetClones;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\Repos\ThreadRepositoryPrompt;
use App\Services\Threads\SandboxRestorer;
use App\Services\Threads\ThreadStarter;
use App\Services\Threads\YakToolRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    Queue::fake();

    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => fn (Request $request) => Http::response([
            'token' => 'ghs_scoped',
            'expires_at' => now()->addHour()->toIso8601String(),
            'repository_selection' => 'selected',
            'repositories' => array_map(fn (string $name): array => ['name' => $name], $request->data()['repositories'] ?? []),
            'permissions' => $request->data()['permissions'] ?? ['contents' => 'read', 'metadata' => 'read'],
        ], 201),
        'api.github.com/app/installations/42' => Http::response(['permissions' => ['contents' => 'write', 'metadata' => 'read']]),
        'api.github.com/repos/*' => Http::response(['description' => null, 'topics' => []]),
    ]);

    $this->user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    foreach (['acme/app', 'acme/web', 'acme/docs', 'acme/extra'] as $slug) {
        Repository::factory()->create(['slug' => $slug]);
    }

    $this->thread = app(ThreadStarter::class)->start($this->user, 'acme/app', TaskMode::Fix, 'Change app and web', $account->id, null, extraRepositories: [
        ['repo' => 'acme/web', 'role' => 'changes'],
        ['repo' => 'acme/docs', 'role' => 'context'],
    ]);
    $this->thread->forceFill(['branch' => 'yak/thread-branch', 'sandbox_state' => ThreadSandboxState::Destroyed])->save();
});

/** Primary checkout (2), setup check (1), refresh config (1), then the extras clone and inspect output. */
function queueRestoreWithTwoExtras(): void
{
    $sandbox = app(SandboxManager::class);

    foreach ([Process::result(), Process::result(), Process::result(exitCode: 1), Process::result(exitCode: 1)] as $result) {
        $sandbox->queueExecResult($result);
    }

    $sandbox->queueExecResult(Process::result("@@YAK_BEGIN 0\n\n@@YAK_EXIT 0 0 1\n@@YAK_BEGIN 1\n\n@@YAK_EXIT 1 0 1\n"));
    $sandbox->queueExecResult(Process::result("@@YAK_INSPECT 0\n@@YAK_HEAD\naaa\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n@@YAK_INSPECT 1\n@@YAK_HEAD\nbbb\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n"));
}

it('starts with a primary, a changes and a context repository at their sandbox paths', function () {
    $rows = $this->thread->repositories()->orderBy('id')->get();

    expect($rows->pluck('role')->all())->toBe([RepositoryRole::Primary, RepositoryRole::Changes, RepositoryRole::Context])
        ->and($rows->pluck('sandbox_path')->all())->toBe(['/workspace', '/extras/web', '/extras/docs']);
});

it('restores extras on the right branches, lists all three in the prompt and attaches a dataset only for the primary', function () {
    $attached = [];
    $this->mock(DatasetClones::class)->shouldReceive('attach')->andReturnUsing(function ($owner, Repository $repository) use (&$attached) {
        $attached[] = $repository->slug;

        return null;
    });
    queueRestoreWithTwoExtras();

    expect(app(SandboxRestorer::class)->restore($this->thread))->toBeTrue();

    $rows = $this->thread->repositories()->orderBy('id')->get()->keyBy('repo');
    expect($rows['acme/web']->setup_status)->toBe(RepositorySetupStatus::Ready)
        ->and($rows['acme/docs']->setup_status)->toBe(RepositorySetupStatus::Ready)
        ->and($attached)->toBe(['acme/app']);

    $clones = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->pluck('arguments.stdin')->first(fn ($stdin) => str_contains((string) $stdin, 'git clone'));
    expect($clones)->toContain("'/extras/web'")->toContain("'/extras/docs'")
        ->toContain("git checkout -B 'yak/thread-branch'")
        ->toContain('git checkout ');
    // Both branches of the changes repo's fetch-or-create line name the thread branch; the context repo checks out the default branch.
    expect(substr_count((string) $clones, "checkout -B 'yak/thread-branch'"))->toBe(2)->and($clones)->toContain("git checkout 'main'");

    $section = ThreadRepositoryPrompt::section($this->thread);
    expect($section)->toContain('acme/app')->toContain('acme/web')->toContain('acme/docs')
        ->and(ThreadRepositoryPrompt::extraDirectories($this->thread))->toBe(['/extras/web', '/extras/docs']);
});

it('answers add_repository and the stack tools through the same router', function () {
    $sandbox = app(SandboxManager::class);
    foreach (range(1, 4) as $ignored) {
        $sandbox->queueExecResult(Process::result());
    }
    $sandbox->queueExecResult(Process::result("@@YAK_BEGIN 0\n\n@@YAK_EXIT 0 0 1\n"));
    $sandbox->queueExecResult(Process::result("@@YAK_INSPECT 0\n@@YAK_HEAD\nabc\n@@YAK_CONFIG\n\n@@YAK_FILES\n\n@@YAK_END\n"));
    $this->thread->forceFill(['sandbox_state' => ThreadSandboxState::Running])->save();

    $router = app(YakToolRouter::class);
    $added = $router->handle($this->thread, new YakToolCall('c1', 'add_repository', ['repo' => 'acme/extra', 'role' => 'context']));
    $listed = $router->handle($this->thread, new YakToolCall('c2', 'list_threads', []));

    expect($added->content)->toContain('Added')
        ->and($added->isError)->toBeFalse()
        ->and($added->content)->toContain('/extras/extra')
        ->and($listed->isError)->toBeFalse()
        ->and($this->thread->repositories()->where('repo', 'acme/extra')->exists())->toBeTrue();
});
