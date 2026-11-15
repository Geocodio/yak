<?php

use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\ThreadActivity;
use App\Events\ThreadEventBroadcast;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventWindow;
use App\Services\Threads\ThreadQuestionEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Support\ContractFixture;
use Tests\Support\InMemoryThreadEventWindow;

const EVENT_FIXTURE_SCENARIOS = ['simple-turn', 'steer', 'question', 'failed-turn', 'stream-reset', 'pointer'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->window = new InMemoryThreadEventWindow;
    app()->instance(ThreadEventWindow::class, $this->window);
    Event::fake([ThreadEventBroadcast::class]);

    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->thread = Thread::factory()->create(['created_by_user_id' => $this->user->id, 'owner_user_id' => $this->user->id, 'title' => 'Retry failed uploads']);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id]);
    $this->run = TaskRun::factory()->create(['yak_task_id' => $this->task->id, 'thread_id' => $this->thread->id, 'turn_state' => TurnState::Running]);
});

/**
 * The lines of a runner fixture as the driver hands them to the realtime layer. The prompt the
 * driver sent is echoed back by the runner as a user line with message content.
 *
 * @return list<array<string, mixed>>
 */
function recordedLines(string $name): array
{
    $lines = file(base_path("tests/Fixtures/runner-streams/{$name}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    array_shift($lines);

    return array_map(function (string $line): array {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        unset($decoded['_ms']);

        if (isset($decoded['_driver_sent']) && ($decoded['type'] ?? null) === 'user') {
            $decoded = ['type' => 'user', 'uuid' => $decoded['uuid'], 'message' => ['role' => 'user', 'content' => $decoded['text']]];
        }

        return $decoded;
    }, $lines);
}

/**
 * @return array<string, mixed>
 */
function textDelta(string $text): array
{
    return ['type' => 'stream_event', 'message_id' => 'm1', 'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]];
}

/**
 * Inserts lines before the first line that satisfies the check.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  list<array<string, mixed>>  $inserted
 * @return list<array<string, mixed>>
 */
function insertBefore(array $lines, callable $check, array $inserted): array
{
    foreach ($lines as $index => $line) {
        if ($check($line)) {
            array_splice($lines, $index, 0, $inserted);

            return $lines;
        }
    }

    throw new RuntimeException('No line matched.');
}

function hasTextBlock(array $line): bool
{
    return ($line['type'] ?? null) === 'assistant' && collect($line['message']['content'] ?? [])->contains(fn (array $block): bool => ($block['type'] ?? null) === 'text');
}

/**
 * The prompt and steer lines the driver sent, read from the runner fixture. A synthetic source
 * has no such lines, so the prompt gets a fixed uuid and text.
 *
 * @return array{prompt: array{uuid: string, text: string}, steer: ?array{uuid: string, text: string}}
 */
function driverSentMessages(string $source): array
{
    $found = ['prompt' => ['uuid' => '00000000-0000-4000-8000-000000000001', 'text' => 'Check the uploads'], 'steer' => null];
    $path = base_path("tests/Fixtures/runner-streams/{$source}.jsonl");

    if (! is_file($path)) {
        return $found;
    }

    $hasPromptLine = false;
    $lifecycleSeen = false;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $sent = $line['_driver_sent'] ?? null;

        if (($line['type'] ?? null) === 'command_lifecycle' && ! $hasPromptLine && ! $lifecycleSeen) {
            $found['prompt']['uuid'] = $line['command_uuid'];
            $lifecycleSeen = true;
        }

        if ($sent === 'prompt') {
            $hasPromptLine = true;
            $found['prompt'] = ['uuid' => $line['uuid'], 'text' => $line['text']];
        } elseif ($sent !== null && $sent !== 'start' && isset($line['uuid'])) {
            $found['steer'] = ['uuid' => $line['uuid'], 'text' => $line['text']];
        }
    }

    return $found;
}

/**
 * Replays a turn the way the server runs it: the prompt message exists before the turn starts,
 * and a steer message is created when the driver delivers it. A line with `_travelMs` first moves
 * the clock forward so the delta batcher closes its window.
 *
 * @param  list<array<string, mixed>>  $lines
 */
function replayLines($test, array $lines, string $source = 'synthetic'): void
{
    $sent = driverSentMessages($source);
    ThreadTurnMessage::factory()->create(['task_run_id' => $test->run->id, 'uuid' => $sent['prompt']['uuid'], 'kind' => TurnMessageKind::Prompt, 'state' => TurnMessageState::Started, 'text' => $sent['prompt']['text']]);

    event(new TurnStarted($test->run));

    foreach ($lines as $index => $line) {
        if (isset($line['_travelMs'])) {
            $test->travel($line['_travelMs'])->milliseconds();
            unset($line['_travelMs']);
        }

        if (($line['type'] ?? null) === 'command_lifecycle') {
            ThreadTurnMessage::query()->where('task_run_id', $test->run->id)->where('uuid', $line['command_uuid'])->update(['state' => TurnMessageState::from($line['state'])]);
        }

        if ($sent['steer'] !== null && ($line['uuid'] ?? null) === $sent['steer']['uuid']) {
            ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $test->run->id, 'uuid' => $sent['steer']['uuid'], 'text' => $sent['steer']['text']]);
        }

        event(new ThreadActivity($test->thread, 1, $index + 1, $line));
    }
}

function lastMessageState(array $broadcasts, string $uuid): ?string
{
    return collect($broadcasts)->where('type', 'message.state')->filter(fn (array $broadcast): bool => ($broadcast['data']['uuid'] ?? null) === $uuid)->last()['data']['state'] ?? null;
}

/**
 * @return list<array<string, mixed>>
 */
function dispatchedBroadcasts(): array
{
    return Event::dispatched(ThreadEventBroadcast::class)->map(fn (array $arguments): array => $arguments[0]->broadcastWith())->values()->all();
}

/**
 * Writes the scenario fixture: the broadcasts, the catch-up read and the pointer reads.
 *
 * @param  list<array<string, mixed>>  $broadcasts
 */
function writeScenario($test, string $scenario, string $source, array $broadcasts): array
{
    $headers = phoneHeadersFor($test->user);
    $catchUp = $test->getJson(appThreadPath($test->thread, '/events?after=0&limit=500'), $headers)->assertOk()->json();

    $pointers = [];
    foreach ($broadcasts as $broadcast) {
        if (($broadcast['pointer'] ?? false) === true) {
            $pointers[] = [
                'sequence' => $broadcast['sequence'],
                'event' => $test->getJson(appThreadPath($test->thread, "/events/{$broadcast['sequence']}"), $headers)->assertOk()->json(),
            ];
        }
    }

    ContractFixture::assertMatches('app', "events/{$scenario}", [
        'scenario' => $scenario,
        'source' => $source,
        'broadcasts' => $broadcasts,
        'catchUp' => $catchUp,
        'pointers' => $pointers,
    ], ['broadcasts.*.data.turnId', 'catchUp.events.*.data.turnId']);

    return ['broadcasts' => $broadcasts, 'catchUp' => $catchUp, 'pointers' => $pointers];
}

it('records a simple turn with deltas, tool use and a completed turn', function () {
    $lines = insertBefore(recordedLines('background-bash-task'), hasTextBlock(...), [textDelta('Checking '), textDelta('the uploads') + ['_travelMs' => 60]]);
    replayLines($this, $lines, 'background-bash-task');
    $this->run->update(['turn_state' => TurnState::Completed]);
    event(new TurnCompleted($this->run));

    $recorded = writeScenario($this, 'simple-turn', 'background-bash-task', dispatchedBroadcasts());
    $types = array_column($recorded['broadcasts'], 'type');
    $entryBlocks = collect($recorded['broadcasts'])->where('type', 'entry')->flatMap(fn (array $broadcast): array => array_column($broadcast['data']['blocks'] ?? [], 'type'));

    expect($types)->toContain('turn', 'delta', 'entry')
        ->and($entryBlocks)->toContain('tool_use')
        ->and(collect($recorded['broadcasts'])->where('type', 'entry')->pluck('data.role'))->toContain('tool_result')
        ->and(collect($recorded['broadcasts'])->where('type', 'turn')->last())->toMatchArray(['data' => ['turnId' => $this->run->id, 'state' => 'completed']])
        ->and(lastMessageState($recorded['broadcasts'], driverSentMessages('background-bash-task')['prompt']['uuid']))->toBe('completed');
});

it('records a steer between two assistant entries', function () {
    $lines = recordedLines('steer-during-final-text');

    replayLines($this, $lines, 'steer-during-final-text');
    $this->run->update(['turn_state' => TurnState::Completed]);
    event(new TurnCompleted($this->run));

    $recorded = writeScenario($this, 'steer', 'steer-during-final-text', dispatchedBroadcasts());
    $roles = collect($recorded['broadcasts'])->where('type', 'entry')->map(fn (array $broadcast): string => $broadcast['data']['role'] . ':' . ($broadcast['data']['kind'] ?? ''))->values();
    $steerIndex = $roles->search('user:steer');
    $sentMessages = driverSentMessages('steer-during-final-text');

    expect(lastMessageState($recorded['broadcasts'], $sentMessages['prompt']['uuid']))->toBe('completed')
        ->and(lastMessageState($recorded['broadcasts'], $sentMessages['steer']['uuid']))->toBe('completed');

    expect($steerIndex)->not->toBeFalse()
        ->and($roles->slice(0, $steerIndex)->contains(fn (string $role): bool => str_starts_with($role, 'assistant')))->toBeTrue()
        ->and($roles->slice($steerIndex + 1)->contains(fn (string $role): bool => str_starts_with($role, 'assistant')))->toBeTrue();
});

it('records the open and close of a question', function () {
    replayLines($this, recordedLines('natural-turn-end'), 'natural-turn-end');
    $this->run->update(['turn_state' => TurnState::Completed]);
    event(new TurnCompleted($this->run));
    app(ThreadQuestionEvents::class)->opened($this->task);
    app(ThreadQuestionEvents::class)->closed($this->task);

    $recorded = writeScenario($this, 'question', 'natural-turn-end', dispatchedBroadcasts());
    $lastTwo = array_slice($recorded['broadcasts'], -2);

    expect($lastTwo[0])->toMatchArray(['type' => 'question', 'data' => ['open' => true]])
        ->and($lastTwo[1])->toMatchArray(['type' => 'question', 'data' => ['open' => false]]);
});

it('records a turn that failed', function () {
    replayLines($this, recordedLines('authentication-failed'), 'authentication-failed');
    $this->run->update(['turn_state' => TurnState::Failed]);
    event(new TurnFailed($this->run));

    $recorded = writeScenario($this, 'failed-turn', 'authentication-failed', dispatchedBroadcasts());
    $turns = collect($recorded['broadcasts'])->where('type', 'turn')->values();

    expect($turns->last()['data']['state'])->toBe('failed')
        ->and(lastMessageState($recorded['broadcasts'], driverSentMessages('authentication-failed')['prompt']['uuid']))->toBe('cancelled');
});

it('records a stream reset that is never stored', function () {
    $lines = insertBefore(recordedLines('cutoff-synthetic-user-message'), fn (array $line): bool => ($line['isSynthetic'] ?? false) === true, [textDelta('Checking the upl')]);
    replayLines($this, $lines, 'cutoff-synthetic-user-message');
    $this->run->update(['turn_state' => TurnState::Completed]);
    event(new TurnCompleted($this->run));

    $recorded = writeScenario($this, 'stream-reset', 'cutoff-synthetic-user-message', dispatchedBroadcasts());
    $types = array_column($recorded['broadcasts'], 'type');
    $resetIndex = array_search('stream.reset', $types, true);

    expect($resetIndex)->not->toBeFalse()
        ->and(array_slice($types, 0, $resetIndex))->toContain('delta');

    $this->window->forget($this->thread->id);
    $fromDatabase = $this->getJson(appThreadPath($this->thread, '/events?after=0'), phoneHeadersFor($this->user))->assertOk()->json();

    expect($fromDatabase['source'])->toBe('database')
        ->and(array_column($fromDatabase['events'], 'type'))->not->toContain('stream.reset', 'delta');
});

it('records an event too large to broadcast as a pointer', function () {
    $text = str_repeat('Checking the upload queue. ', 400);
    replayLines($this, [['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [['type' => 'text', 'text' => $text]]]]]);

    $recorded = writeScenario($this, 'pointer', 'synthetic', dispatchedBroadcasts());
    $pointer = collect($recorded['broadcasts'])->firstWhere('pointer', true);

    expect($pointer)->toBe(['sequence' => $pointer['sequence'], 'pointer' => true])
        ->and($recorded['pointers'][0]['event']['data']['blocks'][0]['text'])->toBe($text);
});

it('writes every scenario', function () {
    foreach (EVENT_FIXTURE_SCENARIOS as $scenario) {
        expect(base_path("tests/Fixtures/app-api/v1/events/{$scenario}.json"))->toBeFile();
    }
});
