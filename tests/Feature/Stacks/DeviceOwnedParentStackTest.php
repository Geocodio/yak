<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\HeldEventKind;
use App\Enums\StackMessageKind;
use App\Enums\ThreadKind;
use App\Enums\TurnState;
use App\Events\StackThresholdReached;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\HeldThreadEvent;
use App\Models\StackMessage;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Stacks\StackResultBatcher;
use App\Services\Stacks\StackToolRouter;
use App\Services\Stacks\StackUsageAlerts;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    [$this->device, $this->token] = makeDevice();
    $this->parent = Thread::factory()->ownedByDevice($this->device->id)->create();
    $this->child = Thread::factory()->child($this->parent)->create(['name' => 'scout', 'kind' => ThreadKind::Research]);
    YakTask::factory()->create(['thread_id' => $this->child->id]);
});

function deviceChildRun(Thread $child, TurnState $state = TurnState::Completed, string $result = 'Found it.'): TaskRun
{
    return TaskRun::factory()->create([
        'thread_id' => $child->id,
        'yak_task_id' => $child->tasks()->first()->id,
        'turn_state' => $state,
        'last_result_event' => ['result' => $result],
    ]);
}

it('keeps children working and queues results in stack_messages for a device-owned parent', function () {
    $other = Thread::factory()->child($this->parent)->create(['name' => 'busy', 'kind' => ThreadKind::Research]);
    YakTask::factory()->create(['thread_id' => $other->id]);
    TaskRun::factory()->create(['thread_id' => $other->id, 'yak_task_id' => $other->tasks()->first()->id, 'turn_state' => TurnState::Running]);

    event(new TurnCompleted(deviceChildRun($this->child)));

    // One child is still running, so the result waits in the batch queue.
    expect(StackMessage::query()->where('thread_id', $this->parent->id)->count())->toBe(0);

    event(new TurnCompleted(deviceChildRun($other, result: 'Also done.')));
    $failing = Thread::factory()->child($this->parent)->create(['name' => 'broken', 'kind' => ThreadKind::Research]);
    YakTask::factory()->create(['thread_id' => $failing->id]);
    event(new TurnFailed(deviceChildRun($failing, TurnState::Failed)));

    $kinds = StackMessage::query()->where('thread_id', $this->parent->id)->orderBy('id')->pluck('kind')->all();

    expect($kinds)->toContain(StackMessageKind::Failure)
        ->and($this->child->fresh()->isOpen())->toBeTrue()
        ->and(StackThreadMessage::query()->whereNull('delivered_at')->count())->toBeLessThanOrEqual(2);

    app(StackResultBatcher::class)->deliverDue(now()->toImmutable()->addHour());

    expect(StackMessage::query()->where('thread_id', $this->parent->id)->where('kind', StackMessageKind::Result)->count())->toBe(2)
        ->and(StackThreadMessage::query()->whereNull('delivered_at')->count())->toBe(0);
});

it('escalates a child question to a held needs-you event, not the parent', function () {
    $result = app(StackToolRouter::class)->handle($this->child, new YakToolCall('call-1', 'send_message', ['to' => 'parent', 'text' => 'Which cache?']));

    $event = HeldThreadEvent::query()->where('thread_id', $this->parent->id)->sole();

    expect($result->isError)->toBeFalse()
        ->and($event->kind)->toBe(HeldEventKind::Other)
        ->and($event->summary)->toBe('Which cache?')
        ->and($event->payload)->toBe(['child_thread_id' => $this->child->id])
        ->and(StackMessage::query()->count())->toBe(0)
        ->and(StackThreadMessage::query()->count())->toBe(0);
});

it('hands waiting results to the CLI fetch endpoint once and acks them', function () {
    event(new TurnCompleted(deviceChildRun($this->child)));
    $url = "/api/cli/v1/threads/{$this->parent->id}/stack-messages";

    $first = $this->getJson($url, cliHeaders($this->token))->assertOk();

    expect($first->json('messages'))->toHaveCount(1)
        ->and($first->json('messages.0'))->toMatchArray(['kind' => 'result'])
        ->and($first->json('messages.0.text'))->toContain('Found it.')
        ->and($first->json('messages.0.from_thread.id'))->toBe($this->child->id);

    $through = $first->json('messages.0.id');
    $this->postJson("{$url}/ack", ['through_id' => $through], cliHeaders($this->token))->assertOk()->assertJsonPath('acked', 1);

    $this->getJson($url, cliHeaders($this->token))->assertOk()->assertJsonPath('messages', []);
});

it('refuses spawn_thread on a device-owned thread', function () {
    $result = app(StackToolRouter::class)->handle($this->parent, new YakToolCall('call-1', 'spawn_thread', ['name' => 'x', 'kind' => 'research', 'brief' => 'b']));

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toContain('Teleport this session to Yak first')
        ->and(Thread::query()->withoutGlobalScopes()->where('parent_thread_id', $this->parent->id)->count())->toBe(1);
});

it('fires the web push hook points', function () {
    Event::fake([StackThresholdReached::class, TurnCompleted::class]);
    config(['yak.threads.stack_alert_children' => 1]);

    event(new TurnCompleted(deviceChildRun($this->child)));
    app(StackUsageAlerts::class)->check($this->parent);

    Event::assertDispatched(TurnCompleted::class);
    Event::assertDispatched(StackThresholdReached::class, fn (StackThresholdReached $event): bool => $event->root->is($this->parent) && $event->threshold === 'children');
});
