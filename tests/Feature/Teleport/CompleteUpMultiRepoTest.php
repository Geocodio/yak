<?php

use App\DataTransferObjects\ResolvedAccount;
use App\DataTransferObjects\SandboxWake;
use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const MULTI_SESSION = 'e4e4e4e4-0000-4000-8000-000000000005';
const MULTI_HEAD = 'feed1234';

/**
 * A sandbox whose exec answers are chosen by a callback from the joined command.
 */
class ScriptedSandboxManager extends FakeSandboxManager
{
    /** @var (Closure(string): ?ProcessResult)|null */
    public ?Closure $script = null;

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        $default = parent::exec($thread, $command, $stdin, $env);

        return $this->script?->__invoke(implode(' ', $command)) ?? $default;
    }
}

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    Repository::factory()->create(['slug' => 'acme/lib', 'github_full_name' => 'acme/lib', 'git_url' => 'https://github.com/acme/lib.git']);
    Repository::factory()->create(['slug' => 'acme/docs', 'github_full_name' => 'acme/docs', 'git_url' => 'https://github.com/acme/docs.git']);
    [$this->device, $this->token] = makeDevice();

    $heads = Mockery::mock(BranchHeadVerifier::class);
    $heads->shouldReceive('headOf')->andReturn(MULTI_HEAD);
    app()->instance(BranchHeadVerifier::class, $heads);

    $resolver = Mockery::mock(ClaudeAccountResolver::class);
    $resolver->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(ClaudeAccount::factory()->system()->create(), 'sonnet'));
    app()->instance(ClaudeAccountResolver::class, $resolver);

    $this->sandboxes = new ScriptedSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);

    $sessions = Mockery::mock(SessionFileSync::class);
    $sessions->shouldReceive('restoreIntoSandbox');
    app()->instance(SessionFileSync::class, $sessions);

    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturnUsing(function (Thread $thread) {
        $thread->forceFill(['sandbox_state' => ThreadSandboxState::Running])->save();

        return SandboxWake::ready();
    });
    app()->instance(ThreadSandbox::class, $sandbox);
    app()->instance(ThreadTurns::class, Mockery::mock(ThreadTurns::class));
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

/**
 * Begins a three-repo up teleport, uploads the session and completes it.
 *
 * @return array{response: TestResponse, thread: Thread, teleport: Teleport}
 */
function completeMultiRepoUp(object $test): array
{
    $repos = [
        ['repo' => 'acme/widgets', 'role' => 'primary', 'local_path' => '/Users/ada/widgets', 'branch' => 'main', 'on_default_branch' => true, 'head_sha' => MULTI_HEAD],
        ['repo' => 'acme/lib', 'role' => 'changes', 'local_path' => '/Users/ada/lib', 'branch' => 'feature/x', 'head_sha' => MULTI_HEAD],
        ['repo' => 'acme/docs', 'role' => 'context', 'local_path' => '/Users/ada/docs'],
    ];
    $begin = $test->postJson('/api/cli/v1/teleports', [
        'direction' => 'up', 'session_id' => MULTI_SESSION, 'title_hint' => 'Multi', 'local_path' => '/Users/ada/widgets', 'repos' => $repos,
    ], cliHeaders($test->token))->assertOk()->json();

    $base = "/api/cli/v1/threads/{$begin['thread']['id']}/session";
    $chunk = "{\"type\":\"user\"}\n";
    $rel = MULTI_SESSION . '.jsonl';
    $test->postJson("{$base}/append", ['session_id' => MULTI_SESSION, 'rel_path' => $rel, 'offset' => 0, 'chunk' => base64_encode($chunk), 'chunk_sha256' => hash('sha256', $chunk), 'tail_sha256' => hash('sha256', '')], cliHeaders($test->token))->assertOk();
    $test->postJson("{$base}/checkpoint", ['session_id' => MULTI_SESSION, 'rel_path' => $rel, 'sha256' => hash('sha256', $chunk)], cliHeaders($test->token))->assertOk();

    Thread::withoutGlobalScopes()->whereKey($begin['thread']['id'])->update(['sandbox_state' => ThreadSandboxState::Running]);

    $response = $test->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", ['repos' => array_map(
        fn (array $entry): array => array_filter(['repo' => $entry['repo'], 'branch' => $entry['role'] === 'primary' ? $begin['repos'][0]['branch'] : ($entry['branch'] ?? null), 'head_sha' => $entry['head_sha'] ?? null]),
        $repos,
    )], cliHeaders($test->token));

    return ['response' => $response, 'thread' => Thread::withoutGlobalScopes()->findOrFail($begin['thread']['id']), 'teleport' => Teleport::query()->findOrFail($begin['teleport_id'])];
}

function multiRepoCommands(object $test): array
{
    return collect($test->sandboxes->calls)->where('operation', 'exec')->map(fn (array $call): string => implode(' ', $call['arguments']['command']))->all();
}

it('links the local path of each changes repo to its extras folder', function () {
    completeMultiRepoUp($this)['response']->assertOk();

    expect(multiRepoCommands($this))->toContain('sh -c mkdir -p "$(dirname "$1")" && ln -sfn "$2" "$1" sh /Users/ada/lib /extras/lib');
});

it('never links a context repo', function () {
    completeMultiRepoUp($this)['response']->assertOk();

    expect(collect(multiRepoCommands($this))->filter(fn (string $command): bool => str_contains($command, '/Users/ada/docs')))->toBeEmpty();
});

it('runs setup and refresh inside each changes extra', function () {
    $this->sandboxes->script = fn (string $command): ?ProcessResult => match (true) {
        $command === 'cat /extras/lib/.yak/config.yml' => Process::result("refresh:\n  'composer.lock': composer install\n"),
        $command === 'git -C /extras/lib ls-files' => Process::result("composer.lock\nsrc/a.php\n"),
        default => null,
    };

    $run = completeMultiRepoUp($this);
    $run['response']->assertOk();

    expect(multiRepoCommands($this))->toContain('test -f /extras/lib/.yak/setup.sh')
        ->toContain('bash /extras/lib/.yak/setup.sh')
        ->toContain('sh -c cd /extras/lib && composer install')
        ->and($run['teleport']->refresh()->payload['refresh_ran'])->toContain('acme/lib: composer install');
});

it('fails the teleport when an extra setup fails', function () {
    $this->sandboxes->script = fn (string $command): ?ProcessResult => $command === 'bash /extras/lib/.yak/setup.sh' ? Process::result(errorOutput: 'nope', exitCode: 1) : null;

    $run = completeMultiRepoUp($this);

    $run['response']->assertStatus(409)->assertJsonPath('error', 'teleport_failed');
    expect($run['thread']->refresh()->owner_side->value)->toBe('device');
});

it('reports sandbox repos with a failed context clone and still completes', function () {
    $this->sandboxes->script = function (string $command): ?ProcessResult {
        return match ($command) {
            'test -d /extras/docs/.git' => Process::result(exitCode: 1),
            'git clone https://github.com/acme/docs.git /extras/docs' => Process::result(errorOutput: 'fatal', exitCode: 128),
            default => null,
        };
    };

    $run = completeMultiRepoUp($this);

    $run['response']->assertOk()
        ->assertJsonPath('sandbox.state', 'ready')
        ->assertJsonPath('sandbox.repos.0', ['repo' => 'acme/widgets', 'role' => 'primary', 'status' => 'ready', 'reason' => null])
        ->assertJsonPath('sandbox.repos.1.repo', 'acme/lib')
        ->assertJsonPath('sandbox.repos.2.status', 'failed');
});
