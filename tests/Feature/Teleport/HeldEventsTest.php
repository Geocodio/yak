<?php

use App\Channels\GitHub\AppService;
use App\Channels\GitHub\PullRequestSummonReplier;
use App\DataTransferObjects\ResolvedAccount;
use App\DataTransferObjects\SandboxWake;
use App\Enums\HeldEventKind;
use App\Jobs\HandlePullRequestSummonJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\TriageReviewJob;
use App\Mail\HeldEventMail;
use App\Models\ClaudeAccount;
use App\Models\GitHubInstallationToken;
use App\Models\HeldThreadEvent;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\FollowUpTaskFactory;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Teleport\HeldEventDelivery;
use App\Services\Teleport\HeldEvents;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__ . '/CliTestHelpers.php';

const HELD_PR = 'https://github.com/acme/web/pull/9';

/**
 * @return array{0: YakTask, 1: Thread, 2: User}
 */
function deviceOwnedTask(bool $private = false): array
{
    $user = User::factory()->create(['slack_user_id' => 'U123']);
    $thread = Thread::factory()->ownedByDevice()->create([
        'owner_user_id' => $user->id,
        'title' => 'Fix the secret cache',
        ...($private ? ['visibility' => 'private'] : []),
    ]);
    $task = YakTask::factory()->awaitingCi()->create([
        'thread_id' => $thread->id, 'repo' => 'acme/web', 'pr_url' => HELD_PR, 'pr_number' => 9, 'source' => 'github',
    ]);

    return [$task, $thread, $user];
}

it('holds a ci failure on a device-owned thread and starts no run', function () {
    Queue::fake();
    Mail::fake();
    [$task, $thread] = deviceOwnedTask();

    (new ProcessCIResultJob($task, false, 'tests failed'))->handle();

    $event = HeldThreadEvent::sole();
    expect($event->thread_id)->toBe($thread->id)
        ->and($event->kind)->toBe(HeldEventKind::CiFailed)
        ->and($event->pull_request_url)->toBe(HELD_PR)
        ->and($task->fresh()->status->value)->toBe('awaiting_ci');
    Queue::assertNothingPushed();
});

it('lets a ci failure on a yak-owned thread proceed', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->awaitingCi()->create(['thread_id' => $thread->id, 'pr_url' => HELD_PR]);

    $held = app(HeldEvents::class)->holdIfDeviceOwned($task, HeldEventKind::CiFailed, 't', 's');

    expect($held)->toBeFalse()->and(HeldThreadEvent::count())->toBe(0);
});

it('holds review comments and summons', function () {
    Queue::fake();
    Mail::fake();
    config()->set('yak.channels.github.installation_id', 99);
    config()->set('yak.followup.github_prefixes', '/yak');
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 't', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'is_active' => true]);
    Http::fake(['api.github.com/*' => Http::response([
        'html_url' => HELD_PR, 'state' => 'open', 'id' => 1,
        'head' => ['ref' => 'feature/x', 'repo' => ['full_name' => 'acme/web']],
        'base' => ['repo' => ['full_name' => 'acme/web']],
    ])]);
    [$task] = deviceOwnedTask();
    $task->update(['status' => 'success']);

    (new TriageReviewJob($task->id, 500, 9, 'changes_requested', 'please fix', 'alice'))->handle(app(AppService::class));
    (new HandlePullRequestSummonJob('acme/web', 9, 'michael', issueCommentId: 42, issueCommentBody: '/yak add a test'))
        ->handle(app(AppService::class), app(FollowUpTaskFactory::class), app(PullRequestSummonReplier::class));

    expect(HeldThreadEvent::orderBy('id')->pluck('kind')->all())->toBe([HeldEventKind::ReviewComment, HeldEventKind::Summon])
        ->and(YakTask::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('keeps event content out of the notice for a private thread', function () {
    Mail::fake();
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    [$task] = deviceOwnedTask(private: true);

    app(HeldEvents::class)->holdIfDeviceOwned($task, HeldEventKind::CiFailed, 'Sensitive title', 'Sensitive summary');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
        && str_contains($request['text'], 'Private thread')
        && ! str_contains($request['text'], 'Sensitive')
        && ! str_contains($request['text'], 'secret cache')
        && ! str_contains($request['text'], HELD_PR));
    Mail::assertSent(HeldEventMail::class, fn (HeldEventMail $mail) => str_contains($mail->notice, 'Private thread')
        && ! str_contains($mail->notice, 'Sensitive') && ! str_contains($mail->notice, HELD_PR));
});

it('names the thread and pull request for a team thread', function () {
    Mail::fake();
    [$task] = deviceOwnedTask();

    app(HeldEvents::class)->holdIfDeviceOwned($task, HeldEventKind::CiFailed, 't', 's');

    Mail::assertSent(HeldEventMail::class, fn (HeldEventMail $mail) => str_contains($mail->notice, 'Fix the secret cache') && str_contains($mail->notice, HELD_PR));
});

it('tints a held event after 24 hours', function () {
    $event = HeldThreadEvent::factory()->create();
    $held = app(HeldEvents::class);

    expect($held->tinted($event))->toBeFalse();
    $this->travel(25)->hours();
    expect($held->tinted($event))->toBeTrue();
});

it('holds a child question when the root is on a device', function () {
    Mail::fake();
    $root = Thread::factory()->ownedByDevice()->create();
    $child = Thread::factory()->child($root)->create();

    expect(app(HeldEvents::class)->holdChildQuestion($child, 'Which table?'))->toBeTrue()
        ->and(HeldThreadEvent::sole()->thread_id)->toBe($root->id)
        ->and(app(HeldEvents::class)->holdChildQuestion(Thread::factory()->child(Thread::factory()->create())->create(), 'q'))->toBeFalse();
});

it('composes the first message and marks delivered once', function () {
    $thread = Thread::factory()->create();
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id, 'title' => 'CI failed', 'pull_request_url' => HELD_PR]);
    $delivery = app(HeldEventDelivery::class);

    expect($delivery->firstMessageFor($thread))->toContain('CI failed')->toContain(HELD_PR)
        ->and($delivery->markDelivered($thread))->toBe(1)
        ->and($delivery->firstMessageFor($thread))->toBeNull()
        ->and($delivery->markDelivered($thread))->toBe(0);
});

describe('teleport up', function () {
    beforeEach(function () {
        $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
        config()->set('yak.threads.session_store_path', $this->storePath);
        config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
        Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
        [$this->device, $this->token] = makeDevice();

        $heads = Mockery::mock(BranchHeadVerifier::class);
        $heads->shouldReceive('headOf')->andReturn('abc123def456');
        app()->instance(BranchHeadVerifier::class, $heads);
        $resolver = Mockery::mock(ClaudeAccountResolver::class);
        $resolver->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(ClaudeAccount::factory()->system()->create(), 'sonnet'));
        app()->instance(ClaudeAccountResolver::class, $resolver);
        app()->instance(SandboxManager::class, new FakeSandboxManager);
        $sync = Mockery::mock(SessionFileSync::class);
        $sync->shouldReceive('restoreIntoSandbox');
        app()->instance(SessionFileSync::class, $sync);
        $sandbox = Mockery::mock(ThreadSandbox::class);
        $sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready());
        app()->instance(ThreadSandbox::class, $sandbox);
        $this->turns = Mockery::mock(ThreadTurns::class);
        app()->instance(ThreadTurns::class, $this->turns);
    });

    afterEach(fn () => File::deleteDirectory($this->storePath));

    /**
     * @return array<string, mixed>
     */
    function uploadedTeleport(object $test): array
    {
        $session = 'd3d3d3d3-0000-4000-8000-000000000009';
        $begin = $test->postJson('/api/cli/v1/teleports', [
            'direction' => 'up', 'session_id' => $session, 'title_hint' => 'Fix', 'local_path' => '/Users/ada/widgets',
            'repos' => [['repo' => 'acme/widgets', 'role' => 'primary', 'branch' => 'main', 'on_default_branch' => true, 'head_sha' => 'abc123def456']],
        ], cliHeaders($test->token))->assertOk()->json();
        $base = "/api/cli/v1/threads/{$begin['thread']['id']}/session";
        $chunk = "{\"type\":\"user\"}\n";
        $test->postJson("{$base}/append", ['session_id' => $session, 'rel_path' => "{$session}.jsonl", 'offset' => 0, 'chunk' => base64_encode($chunk), 'chunk_sha256' => hash('sha256', $chunk), 'tail_sha256' => hash('sha256', '')], cliHeaders($test->token))->assertOk();
        $test->postJson("{$base}/checkpoint", ['session_id' => $session, 'rel_path' => "{$session}.jsonl", 'sha256' => hash('sha256', $chunk)], cliHeaders($test->token))->assertOk();
        HeldThreadEvent::factory()->create(['thread_id' => $begin['thread']['id'], 'title' => 'CI failed']);

        return $begin;
    }

    /**
     * @return array<string, mixed>
     */
    function heldCompleteBody(array $extra = []): array
    {
        return [...$extra, 'repos' => [['repo' => 'acme/widgets', 'branch' => 'yak/fix', 'head_sha' => 'abc123def456']]];
    }

    it('delivers held events once as the first message', function () {
        $begin = uploadedTeleport($this);
        $this->turns->shouldReceive('start')->once()->withArgs(fn ($task, string $prompt) => str_starts_with($prompt, 'While you were away') && str_ends_with($prompt, 'carry on'));

        $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", heldCompleteBody(['message' => 'carry on']), cliHeaders($this->token))
            ->assertOk()->assertJsonPath('held_events_delivered', 1);

        expect(HeldThreadEvent::sole()->delivered_at)->not->toBeNull();
    });

    it('keeps held events undelivered when deliver_held_events is false', function () {
        $begin = uploadedTeleport($this);
        $this->turns->shouldNotReceive('start');

        $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", heldCompleteBody(['deliver_held_events' => false]), cliHeaders($this->token))
            ->assertOk()->assertJsonPath('turn_started', false)->assertJsonPath('held_events_delivered', 0);

        expect(HeldThreadEvent::sole()->delivered_at)->toBeNull();
    });
});
