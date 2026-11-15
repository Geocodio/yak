<?php

use App\Channels\Slack\SenderPolicy;
use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Models\ClaudeAccount;
use App\Models\Device;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Providers\E2eServiceProvider;
use App\Services\E2e\E2eBrokerWire;
use App\Services\E2e\E2eGitHub;
use App\Services\E2e\E2eRepositoryConfig;
use App\Services\E2e\E2eRunnerScript;
use App\Services\E2e\E2eSandboxManager;
use App\Services\E2e\E2eSessionFileSync;
use App\Services\E2e\E2eWorld;
use App\Services\RepositoryConfig;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeBrokerStreams;
use App\Services\Threads\RedisBrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    // The thread event window lives on the real Redis the native server uses.
    $redis = startLocalRedis();

    if ($redis === null) {
        $this->markTestSkipped('redis-server is not installed.');
    }

    $GLOBALS['startedRedisServers'][] = $redis;
    config(['database.redis.thread-window.host' => '127.0.0.1', 'database.redis.thread-window.port' => $redis['port'], 'database.redis.thread-window.url' => null]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    config(['yak.e2e.enabled' => true, 'queue.default' => 'sync', 'yak.channels.github.installation_id' => 1]);
    (new E2eServiceProvider(app()))->bindFakes();
    E2eGitHub::fake();
    app()->singleton(BrokerStreams::class, fn () => new FakeBrokerStreams);

    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user) + ['Accept' => 'application/json'];
    Repository::factory()->create(['slug' => 'example/api', 'name' => 'API', 'is_default' => true, 'github_full_name' => 'example/api']);
    ClaudeAccount::factory()->system()->default()->create(['validated_at' => now()]);
    $this->runner = (object) ['script' => new E2eRunnerScript(pace: 0), 'handled' => [], 'sequences' => [], 'generations' => []];
});

/**
 * Plays the fake runner: feeds every new command on the thread and one-shot channels through the script,
 * pushes its lines into the driver's streams and runs the drivers until they have read them all.
 */
function answerBrokerCommands(Thread $thread): void
{
    $test = test();
    $runner = $test->runner;
    $streams = app(BrokerStreams::class);

    for ($round = 0; $round < 4; $round++) {
        $pushed = false;

        foreach (array_merge($streams->channels('threads:'), $streams->channels('runs:')) as $channel) {
            $commands = $streams->commands($channel);

            foreach (array_slice($commands, $runner->handled[$channel] ?? 0) as $command) {
                if (($command['type'] ?? null) === 'start_runner') {
                    $runner->generations[$channel] = ($runner->generations[$channel] ?? 0) + 1;
                }

                foreach ($runner->script->respond($channel, $command, $runner->generations[$channel] ?? 1) as $scripted) {
                    $streams->pushOut($channel, $runner->generations[$channel] ?? 1, $runner->sequences[$channel] = ($runner->sequences[$channel] ?? 0) + 1, $scripted['line'], $scripted['fromBroker']);
                    $pushed = true;
                }
            }

            $runner->handled[$channel] = count($commands);
        }

        Artisan::call('yak:thread-drivers', ['--once' => true]);

        if (! $pushed) {
            break;
        }
    }

    $test->travel(60)->seconds();
    app(ThreadDriver::class)->tick();
}

afterEach(fn () => stopStartedLocalRedis());

/**
 * Every request the pipeline made went to a faked GitHub URL.
 */
function expectOnlyFakedGitHubRequests(): void
{
    expect(Http::recorded()->map(fn (array $pair): string => (string) $pair[0]->url())->every(fn (string $url): bool => str_starts_with($url, 'https://api.github.com/')))->toBeTrue();
}

/**
 * Records every warning or worse the after-turn work logs. FinishThreadTurnJob catches its own exceptions,
 * so a missing fake shows up only as a log line and a failed task, never as a thrown error.
 *
 * @return object{messages: list<string>}
 */
function recordProblemLogs(): object
{
    $recorded = (object) ['messages' => []];
    Log::listen(function (MessageLogged $logged) use ($recorded): void {
        if (in_array($logged->level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            $recorded->messages[] = $logged->level . ': ' . $logged->message . ' ' . ($logged->context['error'] ?? '');
        }
    });

    return $recorded;
}

function expectAfterTurnWorkSucceeded(Thread $thread, object $problemLogs): void
{
    expect($problemLogs->messages)->toBe([])
        ->and(YakTask::query()->where('thread_id', $thread->id)->where('status', TaskStatus::Failed)->exists())->toBeFalse();
}

function latestRunOf(Thread $thread): TaskRun
{
    return TaskRun::query()->where('thread_id', $thread->id)->latest('id')->firstOrFail();
}

it('starts a thread from the phone and finishes its first turn', function (): void {
    $problemLogs = recordProblemLogs();
    $response = $this->postJson('/api/app/v1/threads', [
        'repo' => 'example/api',
        'mode' => 'fix',
        'description' => 'Retry failed uploads with backoff',
        'send_key' => '5f0c2d1e-3b4a-4c5d-8e6f-7a8b9c0d1e2f',
    ], $this->headers)->assertCreated();

    $thread = Thread::query()->where('public_id', $response->json('id'))->firstOrFail();
    $channel = 'threads:' . $thread->id;
    $types = array_column(app(BrokerStreams::class)->commands($channel), 'type');

    expect($types)->toContain('start_runner')->toContain('turn');

    answerBrokerCommands($thread);

    expect(latestRunOf($thread)->turn_state)->toBe(TurnState::Completed);
    expectAfterTurnWorkSucceeded($thread, $problemLogs);
    expectOnlyFakedGitHubRequests();
});

it('answers a question and finishes the clarification turn', function (): void {
    $problemLogs = recordProblemLogs();
    $thread = Thread::factory()->create(['title' => 'Retry failed uploads', 'repo' => 'example/api', 'created_by_user_id' => $this->user->id, 'owner_user_id' => $this->user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/api', 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $this->user->id, 'clarification_expires_at' => now()->addDay()]);
    $task->recordClarificationRound([
        ClarificationQuestion::fromArray([
            'id' => 'on_failure', 'header' => 'On failure', 'question' => 'What should happen to the row?',
            'options' => [['label' => 'Mark the row as failed'], ['label' => 'Fail the whole batch']], 'multi_select' => false,
        ]),
    ], 'I need one decision.');

    $this->postJson(appThreadPath($thread, '/clarification-answers'), ['answers' => [
        'on_failure' => ['choices' => ['Mark the row as failed'], 'other' => null],
    ], 'note' => null], $this->headers)->assertOk();

    $channel = 'threads:' . $thread->id;
    expect(array_column(app(BrokerStreams::class)->commands($channel), 'type'))->toContain('turn');

    answerBrokerCommands($thread);

    expect(latestRunOf($thread)->turn_state)->toBe(TurnState::Completed);
    expectAfterTurnWorkSucceeded($thread, $problemLogs);
    $this->getJson(appThreadPath($thread), $this->headers)->assertOk()->assertJsonPath('question', null);
});

it('steers and stops a held turn', function (): void {
    $response = $this->postJson('/api/app/v1/threads', [
        'repo' => 'example/api',
        'mode' => 'fix',
        'description' => 'Work on it ' . E2eRunnerScript::HOLD_MARKER,
        'send_key' => '6a0c2d1e-3b4a-4c5d-8e6f-7a8b9c0d1e2f',
    ], $this->headers)->assertCreated();
    $thread = Thread::query()->where('public_id', $response->json('id'))->firstOrFail();

    answerBrokerCommands($thread);
    expect(latestRunOf($thread)->turn_state)->toBe(TurnState::Running);

    $this->postJson(appThreadPath($thread, '/messages'), ['message' => 'Go left', 'send_key' => '7b0c2d1e-3b4a-4c5d-8e6f-7a8b9c0d1e2f'], $this->headers)->assertSuccessful();
    answerBrokerCommands($thread);
    expect(array_column(app(BrokerStreams::class)->commands('threads:' . $thread->id), 'type'))->toContain('steer')
        ->and(latestRunOf($thread)->turn_state)->toBe(TurnState::Running);

    Event::fake([TurnCompleted::class]);
    $this->postJson(appThreadPath($thread, '/stop'), [], $this->headers)->assertSuccessful();
    answerBrokerCommands($thread);

    expect(latestRunOf($thread)->turn_state)->toBe(TurnState::Interrupted);
    Event::assertNotDispatched(TurnCompleted::class);
});

it('opens a pull request against the faked GitHub', function (): void {
    $thread = threadWithChanges($this->user);
    $thread->pullRequestTask()->update(['repo' => 'example/api']);

    $this->postJson(appThreadPath($thread, '/pull-request'), [], $this->headers)->assertStatus(202);

    $primary = $thread->repositories()->where('repo', 'example/api')->firstOrFail();
    expect($primary->pull_request_number)->toBe(E2eGitHub::PULL_REQUEST_NUMBER)
        ->and($primary->pull_request_url)->toBe('https://github.com/example/api/pull/7');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/example/api/pulls');
});

it('refuses an outbound request it does not fake', function (): void {
    expect(fn () => Http::get('https://example.com/'))->toThrow(StrayRequestException::class, 'https://example.com/');
});

it('agrees across processes', function (): void {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $storedState = fn (): ThreadSandboxState => Thread::withoutGlobalScopes()->findOrFail($thread->id)->sandbox_state;
    $first = new E2eSandboxManager;
    $second = new E2eSandboxManager;

    $first->pause($thread);
    expect($storedState())->toBe(ThreadSandboxState::Paused);

    // The second manager and a model that still says Running: only the row says Paused.
    $second->resume(Thread::withoutGlobalScopes()->findOrFail($thread->id));
    expect($storedState())->toBe(ThreadSandboxState::Running);

    // A manager that cached Paused would reject this pause from Paused.
    $staleModel = (clone $thread)->forceFill(['sandbox_state' => ThreadSandboxState::Paused]);
    $first->pause($staleModel);
    expect($storedState())->toBe(ThreadSandboxState::Paused)
        ->and($second->state($thread))->toBe(ThreadSandboxState::Paused);

    $first->stop($thread, 10);
    expect($storedState())->toBe(ThreadSandboxState::Stopped);
});

it('binds what the server needs and nothing from the feature suite', function (): void {
    expect(app(SessionFileSync::class))->toBeInstanceOf(E2eSessionFileSync::class)
        ->and(app(RepositoryConfig::class))->toBeInstanceOf(E2eRepositoryConfig::class)
        ->and(app(SandboxManager::class))->toBeInstanceOf(E2eSandboxManager::class)
        ->and(app(RepositoryConfig::class)->snapshot(Repository::query()->firstOrFail())->state)->toBe('unavailable')
        ->and(app(SenderPolicy::class)::class)->toBe(SenderPolicy::class);

    foreach ([SandboxManager::class, SessionFileSync::class, RepositoryConfig::class, SenderPolicy::class] as $abstract) {
        expect(str_starts_with(app($abstract)::class, 'Tests\\'))->toBeFalse()
            ->and(str_contains(app($abstract)::class, '@anonymous'))->toBeFalse();
    }
    Http::assertNothingSent();
});

it('finishes a new turn on every seeded thread', function (): void {
    $port = (int) end($GLOBALS['startedRedisServers'])['port'];

    foreach (['threads', 'threads-commands'] as $connection) {
        config(["database.redis.{$connection}.host" => '127.0.0.1', "database.redis.{$connection}.port" => $port, "database.redis.{$connection}.url" => null]);
    }
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    app()->singleton(BrokerStreams::class, fn () => new RedisBrokerStreams('threads', 'threads-commands'));

    // The seed needs an empty database; the beforeEach built a person, a repository and an account.
    Device::query()->delete();
    Repository::query()->delete();
    ClaudeAccount::query()->delete();
    User::query()->delete();

    $world = (new E2eWorld(new E2eBrokerWire('threads'), new E2eRunnerScript(pace: 0)))->create('http://127.0.0.1:18000');
    $headers = ['X-Yak-Protocol' => '1', 'Authorization' => 'Bearer ' . $world['phones']['main']['token'], 'Accept' => 'application/json'];
    $threads = $world['threads'];

    // A new process: a new wire and a script without live runners, so the first turn of each thread meets no runner.
    $wire = new E2eBrokerWire('threads');
    $script = new E2eRunnerScript(pace: 0);
    config(['yak.threads.turn_grace_seconds' => 0]);
    $completions = 0;
    Event::listen(TurnCompleted::class, function () use (&$completions): void {
        $completions++;
    });

    $this->postJson("/api/app/v1/threads/{$threads['finished']['id']}/messages", ['message' => 'Once more', 'send_key' => '8c0c2d1e-3b4a-4c5d-8e6f-7a8b9c0d1e2f'], $headers)->assertSuccessful();
    $this->postJson("/api/app/v1/threads/{$threads['question']['id']}/clarification-answers", ['answers' => [
        'on_failure' => ['choices' => ['Mark the row as failed'], 'other' => null],
    ], 'note' => null], $headers)->assertOk();
    $this->postJson("/api/app/v1/threads/{$threads['held']['id']}/messages", ['message' => 'Keep going', 'send_key' => '9d0c2d1e-3b4a-4c5d-8e6f-7a8b9c0d1e2f'], $headers)->assertSuccessful();

    $noRunner = [];
    for ($pass = 0; $pass < 50; $pass++) {
        $isWritten = false;

        foreach ($wire->readCommands($wire->channels(), 10, 50) as $entry) {
            $command = $entry['command'] ?? [];
            $channel = $entry['channel'];
            $generation = ($command['type'] ?? null) === 'start_runner' ? $wire->startGeneration($channel) : $wire->generation($channel);

            foreach ($script->respond($channel, $command, $generation) as $scripted) {
                $noRunner[$channel] ??= ($scripted['line']['type'] ?? null) === 'broker.no_runner';
                $wire->write($channel, $scripted['line'], $scripted['fromBroker'], $generation);
                $isWritten = true;
            }
        }

        Artisan::call('yak:thread-drivers', ['--once' => true]);

        if (! $isWritten) {
            break;
        }
    }

    foreach (['finished', 'question', 'held'] as $key) {
        $thread = Thread::withoutGlobalScopes()->where('public_id', $threads[$key]['id'])->firstOrFail();
        expect($noRunner['threads:' . $thread->id] ?? false)->toBeTrue()
            ->and($thread->runner_generation)->toBe(2)
            ->and(TaskRun::query()->where('thread_id', $thread->id)->where('turn_state', TurnState::Completed)->count())->toBe(2);
    }

    expect($completions)->toBe(3);
});
