<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\DeploymentContainerManager;
use App\Services\PreviewAccess;
use App\Services\Push\ThreadPresence;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadTurns;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\Support\FakePushEndpoint;

require_once __DIR__ . '/../../Support/ThreadPreviewHelpers.php';
require_once __DIR__ . '/Realtime/RealtimeRedis.php';

afterEach(fn () => stopThrowawayRedis());

it('walks a spawned child from tool call to batched result, stack panel, preview wake and push suppression', function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com', 'yak.deployments.internal.ingress_ip_cidr' => '0.0.0.0/0']);
    $this->mock(DeploymentContainerManager::class)->shouldReceive('resolveContainerIp')->andReturn('10.0.0.7');
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
    $push = FakePushEndpoint::install();

    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $owner->id]);
    Repository::factory()->create(['slug' => 'org/my-repo', 'path' => '/home/yak/repos/my-repo']);
    $parent = Thread::factory()->private($owner)->create(['repo' => 'org/my-repo', 'claude_account_id' => $account->id, 'model' => 'sonnet', 'branch' => 'yak/parent']);
    $parent->repositories()->create(['repo' => 'org/my-repo', 'role' => 'primary', 'sandbox_path' => '/workspace', 'branch' => 'yak/parent']);
    $parentTask = YakTask::factory()->create(['thread_id' => $parent->id, 'repo' => 'org/my-repo']);

    app()->singleton(SandboxManager::class, fn () => new class extends FakeSandboxManager
    {
        public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
        {
            $result = parent::exec($thread, $command, $stdin, $env);

            return match (implode(' ', $command)) {
                'git status --porcelain' => Process::result(output: 'M a.txt'),
                'git rev-list --count HEAD --not --remotes=origin' => Process::result(output: '1'),
                default => $result,
            };
        }
    });
    $sandboxes = app(SandboxManager::class);
    $sandboxes->create($parent);
    $sandboxes->start($parent);
    $parent->forceFill(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-parent'])->save();

    $parentRun = app(ThreadTurns::class)->start($parentTask, 'Plan the work', $owner, null);
    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);

    // The parent's runner asks Yak to spawn an implement child.
    $driver->handle($streams->pushOut('threads:' . $parent->id, 1, 1, ['type' => 'runner', 'event' => 'started', 'pid' => 1]));
    $driver->handle($streams->pushOut('threads:' . $parent->id, 1, 2, [
        'type' => 'yak.tool_call', 'call_id' => 'call-1', 'tool' => 'spawn_thread',
        'input' => ['name' => 'docs', 'brief' => 'Write the docs.', 'kind' => 'implement'],
    ]));

    $child = Thread::query()->withoutGlobalScopes()->where('parent_thread_id', $parent->id)->sole();
    expect($child->kind)->toBe(ThreadKind::Implement)
        ->and($child->claude_account_id)->toBe($account->id)
        ->and($child->owner_user_id)->toBe($owner->id);
    $toolResult = collect($streams->commands('threads:' . $parent->id))->firstWhere('type', 'tool_result');
    expect($toolResult['is_error'])->toBeFalse();

    // The child's turn completes from a fixture, then its branch is pushed.
    $childRun = TaskRun::query()->where('thread_id', $child->id)->sole();
    expect($childRun->turn_state)->toBe(TurnState::Running);
    $sequence = 0;
    foreach (file(base_path('tests/Fixtures/runner-streams/natural-turn-end.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode(str_replace('f429399e-374d-5e1e-a7cc-65fce3bd2a28', $childRun->prompt_uuid, $raw), true);
        if (isset($line['fixture'])) {
            continue;
        }
        $driver->handle($streams->pushOut('threads:' . $child->id, 1, ++$sequence, $line, fromBroker: str_starts_with((string) ($line['type'] ?? ''), 'broker')));
    }
    $this->travel(60)->seconds();
    $driver->tick();
    $driver->tick();

    $pushes = collect($sandboxes->calls)->where('threadId', $child->id)->filter(fn (array $call): bool => $call['operation'] === 'exec' && in_array('push', $call['arguments']['command'], true));
    expect($pushes)->not->toBeEmpty();

    // The parent receives the batched result as an input line.
    $inputs = collect($streams->commands('threads:' . $parent->id))->filter(fn (array $command): bool => str_contains(json_encode($command), 'Results from sub-threads'));
    expect($inputs)->not->toBeEmpty();

    // The Stack panel lists the child with its state.
    $this->actingAs($owner)->getJson(route('threads.stack.show', $parent))->assertOk()
        ->assertJsonPath('children.0.name', $child->name)
        ->assertJsonPath('children.0.state', 'done');

    // Preview: the owner wakes the paused parent; a stranger gets not-found.
    $sandboxes->pause($parent);
    $parent->forceFill(['sandbox_state' => ThreadSandboxState::Paused])->save();
    $host = app(ThreadPreviewHosts::class)->hostnameFor($parent);
    $wake = fn (User $user) => $this->withHeaders(['X-Forwarded-Host' => $host, 'X-Forwarded-Uri' => '/'])
        ->withCookie(PreviewAccess::COOKIE_NAME, previewCookie($host, $user->id))
        ->get('/internal/threads/preview/wake');

    Sleep::fake(syncWithCarbon: true);
    config(['yak.threads.preview_hold_seconds' => 1]);
    queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
    queueSandboxResults(10, 'refused', 7);
    $wake($owner)->assertStatus(425);
    for ($drained = 0; $drained < 12; $drained++) {
        $sandboxes->exec($parent, ['true']);
    }
    config(['yak.threads.preview_hold_seconds' => 10]);
    queueHealthyPreview();
    $wake($owner)->assertOk()->assertHeader('X-Upstream-Host', '10.0.0.7');
    expect(collect($sandboxes->calls)->where('threadId', $parent->id)->pluck('operation'))->toContain('resume');

    $wake($stranger)->assertNotFound();

    // A finished turn pushes the driver only when they are not present.
    $parentRun->update(['turn_state' => TurnState::Completed, 'driver_user_id' => $owner->id, 'last_result_event' => ['result' => 'Done']]);
    $push->subscription($owner);
    $push->respondWith();
    event(new TurnCompleted($parentRun->fresh()));
    expect($push->requests())->toHaveCount(1);

    $this->mock(ThreadPresence::class)->shouldReceive('isViewing')->andReturnTrue();
    event(new TurnCompleted($parentRun->fresh()));
    expect($push->requests())->toHaveCount(1);
});
