<?php

use App\DataTransferObjects\ResolvedAccount;
use App\DataTransferObjects\SandboxWake;
use App\Enums\TeleportState;
use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\SessionStore\SessionStore;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Teleport\Teleports;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

const LEAK_SESSION = 'b2b2b2b2-0000-4000-8000-000000000009';
const LEAK_HEAD = 'abc123def456';
const LEAK_CREDENTIAL = 'https://ada:ghp_SuperSecretCredential1234567890abcd@github.com/acme/widgets.git';
const LEAK_EMAIL = 'ada.private.mail@example.com';

class LeakScriptedSandbox extends FakeSandboxManager
{
    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        parent::exec($thread, $command, $stdin, $env);
        $line = implode(' ', $command);

        return match (true) {
            str_contains($line, 'status --porcelain') => Process::result(output: " M app.php\n"),
            str_contains($line, 'rev-list') => Process::result(output: "0\n"),
            str_contains($line, 'sha256sum') => Process::result(output: hash('sha256', app(SessionStore::class)->read($thread, LEAK_SESSION, LEAK_SESSION . '.jsonl')) . "  file\n"),
            str_contains($line, 'rev-parse HEAD') => Process::result(output: LEAK_HEAD . "\n"),
            default => Process::result(),
        };
    }
}

it('never lets the bearer token or a git credential reach stored or emitted data', function () {
    $storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    config()->set('broadcasting.default', 'log');
    config()->set('queue.default', 'sync');

    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    [$device, $token] = makeDevice();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message . ' ' . json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $heads = Mockery::mock(BranchHeadVerifier::class);
    $heads->shouldReceive('headOf')->andReturn(LEAK_HEAD);
    app()->instance(BranchHeadVerifier::class, $heads);
    $resolver = Mockery::mock(ClaudeAccountResolver::class);
    $resolver->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(ClaudeAccount::factory()->system()->create(), 'sonnet'));
    app()->instance(ClaudeAccountResolver::class, $resolver);
    app()->instance(SandboxManager::class, new LeakScriptedSandbox);
    $sessions = Mockery::mock(SessionFileSync::class);
    $sessions->shouldReceive('restoreIntoSandbox');
    $sessions->shouldReceive('syncFromSandbox');
    app()->instance(SessionFileSync::class, $sessions);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturnUsing(function (Thread $thread) {
        $thread->forceFill(['sandbox_state' => ThreadSandboxState::Running])->save();

        return SandboxWake::ready();
    });
    $sandbox->shouldReceive('touch');
    app()->instance(ThreadSandbox::class, $sandbox);
    app()->instance(ThreadTurns::class, Mockery::mock(ThreadTurns::class));

    $headers = cliHeaders($token);
    $repos = [['repo' => 'acme/widgets', 'role' => 'primary', 'branch' => 'main', 'on_default_branch' => true, 'head_sha' => LEAK_HEAD]];
    $beginUp = fn (): array => $this->postJson('/api/cli/v1/teleports', [
        'direction' => 'up', 'session_id' => LEAK_SESSION, 'title_hint' => 'Fix the cache', 'local_path' => '/Users/ada/widgets',
        'git_name' => 'Ada', 'git_email' => LEAK_EMAIL, 'os' => 'darwin', 'arch' => 'arm64', 'repos' => $repos,
    ], $headers)->assertOk()->json();

    // begin up, upload, complete up
    $up = $beginUp();
    $base = "/api/cli/v1/threads/{$up['thread']['id']}/session";
    $chunk = "{\"type\":\"user\"}\n";
    $rel = LEAK_SESSION . '.jsonl';
    $this->postJson("{$base}/append", ['session_id' => LEAK_SESSION, 'rel_path' => $rel, 'offset' => 0, 'chunk' => base64_encode($chunk), 'chunk_sha256' => hash('sha256', $chunk), 'tail_sha256' => hash('sha256', '')], $headers)->assertOk();
    $this->postJson("{$base}/checkpoint", ['session_id' => LEAK_SESSION, 'rel_path' => $rel, 'sha256' => hash('sha256', $chunk)], $headers)->assertOk();
    $this->postJson("/api/cli/v1/teleports/{$up['teleport_id']}/complete", ['repos' => [['repo' => 'acme/widgets', 'branch' => 'yak/fix-the-cache', 'head_sha' => LEAK_HEAD]]], $headers)->assertOk();
    $threadId = $up['thread']['id'];

    // begin down, prepare, complete down
    $down = $this->postJson('/api/cli/v1/teleports', ['direction' => 'down', 'thread_id' => $threadId, 'local_path' => '/Users/ada/widgets', 'os' => 'darwin', 'arch' => 'arm64', 'visibility' => 'keep'], $headers)->assertOk()->json();
    expect(Teleport::query()->find($down['teleport_id'])->state)->toBe(TeleportState::Ready);
    $this->postJson("/api/cli/v1/teleports/{$down['teleport_id']}/complete", [
        'applied' => [['repo' => 'acme/widgets', 'status' => 'applied', 'mode' => 'fast_forward', 'head_sha' => LEAK_HEAD, 'path' => '/Users/ada/widgets']],
        'downloaded' => [['session_id' => LEAK_SESSION, 'rel_path' => $rel, 'sha256' => hash('sha256', $chunk)]],
    ], $headers)->assertOk();

    // cancel with a credential in the detail
    $cancelled = $beginUp();
    $this->postJson("/api/cli/v1/teleports/{$cancelled['teleport_id']}/cancel", ['reason' => 'error', 'detail' => 'git said ' . LEAK_CREDENTIAL . ' and Bearer ' . $token], $headers)->assertOk();

    // fail with a credential in the message
    $failing = $beginUp();
    app(Teleports::class)->fail(Teleport::query()->findOrFail($failing['teleport_id']), 'teleport_failed', 'clone of ' . LEAK_CREDENTIAL . ' failed with ' . $token);

    $dumps = [
        'teleports' => DB::table('teleports')->get()->toJson(),
        'task_logs' => DB::table('task_logs')->get()->toJson(),
        'session_files' => DB::table('session_files')->get()->toJson(),
        'stack_messages' => DB::table('stack_messages')->get()->toJson(),
        'logs' => implode("\n", $logged),
        'files' => collect(File::allFiles($storePath))->map(fn ($file): string => (string) file_get_contents($file->getPathname()))->implode("\n"),
    ];

    foreach ($dumps as $name => $text) {
        expect($text)->not->toContain($token, "token in {$name}")
            ->not->toContain('ghp_SuperSecretCredential', "credential in {$name}")
            ->not->toContain(LEAK_EMAIL, "git email in {$name}");
    }

    expect(Teleport::query()->where('state', TeleportState::Failed->value)->value('failure_message'))->toContain('[redacted]');
    expect(Thread::withoutGlobalScopes()->find($threadId)->git_author_email)->toBe(LEAK_EMAIL);

    File::deleteDirectory($storePath);
});
