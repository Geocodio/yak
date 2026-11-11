<?php

use App\Enums\TaskRunKind;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadVisibility;
use App\Enums\TurnState;
use App\Events\ThreadEventBroadcast;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadSession;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadShell;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSandboxManager as ShellFake;

require_once __DIR__ . '/Realtime/RealtimeRedis.php';
require_once __DIR__ . '/../Teleport/CliTestHelpers.php';

afterEach(fn () => stopThrowawayRedis());

it('runs a dashboard thread from the start screen to a pull request with realtime events and a captured diff', function () {
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
    Event::fake([ThreadEventBroadcast::class]);
    configureFakeGitHubApp();
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_test', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/*/pulls?*' => Http::response([]),
        'api.github.com/repos/*/pulls' => Http::response(['number' => 42, 'html_url' => 'https://github.com/org/my-repo/pull/42']),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
        '*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $shell = new ShellFake;
    app()->bind(ThreadShell::class, fn () => $shell);
    Repository::factory()->create(['slug' => 'org/my-repo', 'path' => '/home/yak/repos/my-repo', 'default_branch' => 'main', 'ci_system' => 'none', 'is_active' => true]);
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $user->id]);

    $this->actingAs($user)->post(route('threads.store'), [
        'repo' => 'org/my-repo',
        'mode' => 'fix',
        'description' => 'Fix the geocode cache',
        'claude_account_id' => $account->id,
        'model' => 'sonnet',
    ])->assertRedirect();

    $thread = Thread::query()->latest('id')->firstOrFail();
    $task = YakTask::query()->where('thread_id', $thread->id)->sole();
    $run = $task->runs()->sole();
    expect($thread->claude_account_id)->toBe($account->id)
        ->and($thread->model)->toBe('sonnet')
        ->and($run->kind)->toBe(TaskRunKind::Initial)
        ->and($run->turn_state)->toBe(TurnState::Running)
        ->and($task->branch_name)->not->toBeNull();

    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);
    $sequence = 0;

    foreach (file(base_path('tests/Fixtures/runner-streams/natural-turn-end.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode(str_replace('f429399e-374d-5e1e-a7cc-65fce3bd2a28', $run->prompt_uuid, $raw), true);

        if (isset($line['fixture'])) {
            continue;
        }

        $driver->handle($streams->pushOut('threads:' . $thread->id, 1, ++$sequence, $line, fromBroker: str_starts_with((string) ($line['type'] ?? ''), 'broker')));
    }

    $this->travel(60)->seconds();
    $driver->tick();
    $driver->tick();

    // After turn: pushed the branch and opened the pull request.
    expect($shell->commandsMatching("git push origin {$task->branch_name}"))->toHaveCount(1);
    expect($task->refresh()->pr_url)->toBe('https://github.com/org/my-repo/pull/42');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls') && $request['head'] === $task->branch_name);

    // Realtime: monotonic sequence on the thread's private channel, and catch-up returns the same events.
    $broadcasts = Event::dispatched(ThreadEventBroadcast::class)->map(fn (array $arguments): ThreadEventBroadcast => $arguments[0])->all();
    $sequences = array_map(fn (ThreadEventBroadcast $event): int => $event->sequence, $broadcasts);
    expect($broadcasts)->not->toBeEmpty()
        ->and($sequences)->toBe(range(1, count($sequences)))
        ->and($broadcasts[0]->broadcastOn()[0]->name)->toBe('private-thread.' . $thread->id);

    $catchUp = $this->getJson(route('threads.events.index', ['thread' => $thread, 'after' => 0]))->assertOk();
    expect(array_column($catchUp->json('events'), 'sequence'))->toBe($sequences)
        ->and($catchUp->json('latestSequence'))->toBe(max($sequences));

    // Changes: the diff was captured.
    expect(ThreadDiff::query()->where('thread_id', $thread->id)->exists())->toBeTrue();
});

it('teleports a local session up into a private Yak thread that nobody else can see', function () {
    $this->withoutVite();
    [$device, $token] = makeDevice();
    $other = User::factory()->create();
    $admin = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);
    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets', 'git_url' => 'https://github.com/acme/widgets.git']);

    $begin = $this->postJson('/api/cli/v1/teleports', [
        'direction' => 'up',
        'session_id' => 'e4e4e4e4-0000-4000-8000-000000000005',
        'title_hint' => 'Fix the geocode cache',
        'local_path' => '/Users/ada/widgets',
        'repos' => [['repo' => 'acme/widgets', 'role' => 'primary', 'local_path' => '/Users/ada/widgets', 'branch' => 'main', 'on_default_branch' => true, 'head_sha' => 'abc123']],
    ], cliHeaders($token))->assertOk();

    $thread = Thread::withoutGlobalScopes()->findOrFail($begin->json('thread.id'));
    expect($thread->visibility)->toBe(ThreadVisibility::Private)
        ->and($thread->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and(YakTask::query()->where('thread_id', $thread->id)->value('source'))->toBe(YakTask::SOURCE_TELEPORT)
        ->and(Teleport::query()->find($begin->json('teleport_id'))->state)->toBe(TeleportState::Open)
        ->and(ThreadSession::query()->where('thread_id', $thread->id)->exists())->toBeTrue();

    foreach ([$other, $admin] as $stranger) {
        $this->actingAs($stranger)->get(route('threads.show', $thread->id))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('threads.list', ['filter' => 'everyone']))->assertJsonCount(0, 'threads');
    }
});
