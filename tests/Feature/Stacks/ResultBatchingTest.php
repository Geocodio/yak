<?php

use App\Enums\StackMessageKind;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Enums\TurnState;
use App\Models\PendingSteeringMessage;
use App\Models\StackMessage;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Stacks\StackResultBatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

function batchChild(Thread $parent, string $name, TurnState $state = TurnState::Completed, array $attributes = []): Thread
{
    $child = Thread::factory()->child($parent)->create(['name' => $name, 'kind' => ThreadKind::Implement, ...$attributes]);
    $task = YakTask::factory()->create(['thread_id' => $child->id]);
    TaskRun::factory()->create(['thread_id' => $child->id, 'yak_task_id' => $task->id, 'turn_state' => $state]);

    return $child;
}

function queueResult(Thread $parent, Thread $child, string $text, ?string $branch = null, StackThreadMessageKind $kind = StackThreadMessageKind::Result, bool $queued = true): StackThreadMessage
{
    return StackThreadMessage::factory()->create([
        'root_thread_id' => $parent->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $parent->id,
        'kind' => $kind,
        'text' => $text,
        'branch' => $branch,
        'queued_at' => $queued ? now() : null,
    ]);
}

it('holds results until every child is done', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    $done = batchChild($parent, 'done');
    $working = batchChild($parent, 'working', TurnState::Running);
    queueResult($parent, $done, 'First result');

    expect(app(StackResultBatcher::class)->deliverDue(CarbonImmutable::now()))->toBe(0)
        ->and(StackThreadMessage::query()->whereNull('delivered_at')->count())->toBe(1);

    TaskRun::query()->where('thread_id', $working->id)->update(['turn_state' => TurnState::Completed]);

    expect(app(StackResultBatcher::class)->deliverDue(CarbonImmutable::now()))->toBe(1)
        ->and(StackThreadMessage::query()->whereNull('delivered_at')->count())->toBe(0);
});

it('delivers after the wait window', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    queueResult($parent, batchChild($parent, 'done'), 'First result');
    batchChild($parent, 'working', TurnState::Running);

    $this->travel(11)->minutes();

    expect(app(StackResultBatcher::class)->deliverDue(CarbonImmutable::now()))->toBe(1)
        ->and(StackMessage::query()->where('thread_id', $parent->id)->count())->toBe(1);
});

it('delivers one combined message for two finished children', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn')->andReturnNull()->byDefault();
    [$parent, $task] = threadWithTask();
    $first = batchChild($parent, 'api', attributes: ['claude_account_id' => $parent->claude_account_id]);
    $second = batchChild($parent, 'ui');
    queueResult($parent, $first, 'API done', 'yak/p/api');
    queueResult($parent, $second, 'UI done', 'yak/p/ui');

    expect(app(StackResultBatcher::class)->deliverDue(CarbonImmutable::now()))->toBe(1);

    $prompt = TaskRun::query()->where('thread_id', $parent->id)->where('turn_state', '!=', TurnState::Completed->value)->latest('id')->firstOrFail()->prompt;

    expect(TaskRun::query()->where('thread_id', $parent->id)->count())->toBe(1)
        ->and($prompt)->toContain('Result from api (implement): API done')->toContain('Branch: yak/p/api')
        ->and($prompt)->toContain('Result from ui (implement): UI done')->toContain('Branch: yak/p/ui')
        ->and($prompt)->toContain('Fetch and merge each implement branch');
});

it('delivers once when the command runs twice', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    queueResult($parent, batchChild($parent, 'done'), 'Result');

    Artisan::call('yak:deliver-stack-results');
    Artisan::call('yak:deliver-stack-results');

    expect(StackMessage::query()->where('thread_id', $parent->id)->count())->toBe(1);
});

it('claims each row once when two batchers race', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    $row = queueResult($parent, batchChild($parent, 'done'), 'Result');

    $first = app(StackResultBatcher::class)->deliverFor($parent, true);
    $second = app(StackResultBatcher::class)->deliverFor($parent, true);

    expect($first)->toBeTrue()->and($second)->toBeFalse()
        ->and($row->fresh()->delivered_at)->not->toBeNull()
        ->and(StackMessage::query()->count())->toBe(1);
});

it('steers a busy parent instead of starting a turn', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('steer', 'queue')->andReturn(true)->byDefault();
    [$parent, $task] = threadWithTask();
    TaskRun::factory()->create(['thread_id' => $parent->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Running]);
    queueResult($parent, batchChild($parent, 'api'), 'API done');

    app(StackResultBatcher::class)->deliverDue(CarbonImmutable::now());

    expect(PendingSteeringMessage::query()->where('root_task_id', $task->id)->value('text'))->toContain('API done')
        ->and(TaskRun::query()->where('thread_id', $parent->id)->count())->toBe(1);
});

it('writes stack_messages for a device-owned parent and starts no turn', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    queueResult($parent, batchChild($parent, 'api'), 'API done', 'yak/p/api');
    queueResult($parent, batchChild($parent, 'ui'), 'UI done');

    app(StackResultBatcher::class)->deliverDue(CarbonImmutable::now());

    $messages = StackMessage::query()->where('thread_id', $parent->id)->orderBy('id')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('kind')->unique()->all())->toBe([StackMessageKind::Result])
        ->and($messages[0]->text)->toContain('API done')->toContain('yak/p/api')
        ->and(TaskRun::query()->where('thread_id', $parent->id)->count())->toBe(0);
});

it('delivers a failure at once', function () {
    $parent = Thread::factory()->ownedByDevice()->create();
    $failed = batchChild($parent, 'broken', TurnState::Failed);
    batchChild($parent, 'working', TurnState::Running);
    $row = queueResult($parent, $failed, 'It crashed.', kind: StackThreadMessageKind::Failure, queued: false);

    expect(app(StackResultBatcher::class)->deliverImmediate($row))->toBeTrue()
        ->and(StackMessage::query()->where('thread_id', $parent->id)->sole()->kind)->toBe(StackMessageKind::Failure)
        ->and(app(StackResultBatcher::class)->deliverImmediate($row))->toBeFalse();
});

it('marks rows delivered and sends nothing to a closed parent', function () {
    $parent = Thread::factory()->ownedByDevice()->closed()->create();
    $row = queueResult($parent, batchChild($parent, 'done'), 'Result');

    app(StackResultBatcher::class)->deliverDue(CarbonImmutable::now());

    expect($row->fresh()->delivered_at)->not->toBeNull()
        ->and(StackMessage::query()->count())->toBe(0);
});
