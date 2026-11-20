<?php

use App\DataTransferObjects\ThreadMessageInput;
use App\DataTransferObjects\ThreadMessageResult;
use App\Enums\MessageDelivery;
use App\Enums\RunnerState;
use App\Enums\ThreadAgentStatus;
use App\Enums\ThreadVisibility;
use App\Exceptions\ThreadActionRefused;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadAgents;
use App\Services\Threads\ThreadMessageSender;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->create(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $this->owner->id, 'runner_state' => RunnerState::Idle]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id]);
    $this->parent = ThreadAgent::factory()->create(['thread_id' => $this->thread->id, 'task_id' => 'aparent', 'description' => 'Research agent']);
    $this->child = ThreadAgent::factory()->childOf($this->parent)->create(['task_id' => 'achild']);
    $this->grandchild = ThreadAgent::factory()->childOf($this->child)->create(['task_id' => 'agrandchild']);
    $this->sibling = ThreadAgent::factory()->create(['thread_id' => $this->thread->id, 'task_id' => 'asibling']);
    $this->finished = ThreadAgent::factory()->finished()->childOf($this->parent)->create(['task_id' => 'adone']);
});

function stopCommands(Thread $thread): array
{
    return array_values(array_map(fn (array $command): string => $command['taskId'], array_filter(app(BrokerStreams::class)->commands('threads:' . $thread->id), fn (array $command): bool => $command['type'] === 'stop_agent')));
}

it('stops an agent and every running agent it started, and leaves its siblings', function () {
    $this->actingAs($this->owner)->post(route('threads.agents.stop', [$this->thread, 'aparent']))->assertRedirect()->assertSessionHas('success');

    expect(stopCommands($this->thread))->toBe(['aparent', 'achild', 'agrandchild'])
        ->and($this->parent->refresh()->stop_requested_at)->not->toBeNull()
        ->and($this->sibling->refresh()->stop_requested_at)->toBeNull()
        ->and($this->finished->refresh()->stop_requested_at)->toBeNull()
        ->and($this->parent->status)->toBe(ThreadAgentStatus::Running);
});

it('stops only the agent when it started none', function () {
    $this->actingAs($this->owner)->post(route('threads.agents.stop', [$this->thread, 'asibling']))->assertRedirect();

    expect(stopCommands($this->thread))->toBe(['asibling']);
});

it('stops every running agent', function () {
    $this->actingAs($this->owner)->post(route('threads.agents.stop-all', $this->thread))->assertRedirect()->assertSessionHas('success');

    expect(stopCommands($this->thread))->toBe(['aparent', 'achild', 'agrandchild', 'asibling']);
});

it('tells the person when there is nothing to stop', function () {
    ThreadAgent::query()->update(['status' => ThreadAgentStatus::Completed, 'finished_at' => now()]);

    $this->actingAs($this->owner)->post(route('threads.agents.stop-all', $this->thread))->assertSessionHas('error');
    $this->actingAs($this->owner)->post(route('threads.agents.stop', [$this->thread, 'aparent']))->assertSessionHas('error');

    expect(stopCommands($this->thread))->toBe([]);
});

it('fails the agents instead of asking a runner that is gone', function () {
    $this->thread->forceFill(['runner_state' => RunnerState::None])->save();

    $this->actingAs($this->owner)->post(route('threads.agents.stop-all', $this->thread))->assertRedirect();

    expect(stopCommands($this->thread))->toBe([])
        ->and(ThreadAgent::query()->running()->count())->toBe(0)
        ->and($this->sibling->refresh()->summary)->toBe(ThreadAgents::RUNNER_LOST)
        ->and(ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'agent.finished')->count())->toBe(4);
});

it('answers an agent of another thread as missing', function () {
    $other = Thread::factory()->create(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->post(route('threads.agents.stop', [$other, 'aparent']))->assertNotFound();
    $this->actingAs($this->owner)->get(route('threads.agents.log', [$this->thread, 'nope']))->assertNotFound();
    expect(stopCommands($this->thread))->toBe([]);
});

it('keeps the controls closed to a person who can only view', function () {
    $world = VisibilityWorld::build();
    $viewer = $world->sharedViewer;
    ThreadAgent::factory()->create(['thread_id' => $world->thread->id, 'task_id' => 'aparent']);
    $this->thread = $world->thread;

    $this->actingAs($viewer)->post(route('threads.agents.stop', [$this->thread, 'aparent']))->assertForbidden();
    $this->actingAs($viewer)->post(route('threads.agents.stop-all', $this->thread))->assertForbidden();
    $this->actingAs($viewer)->post(route('threads.agents.messages.store', [$this->thread, 'aparent']), ['message' => 'hi'])->assertForbidden();
    $this->actingAs($viewer)->getJson(route('threads.agents.log', [$this->thread, 'aparent']))->assertOk();
    $this->actingAs($world->other)->getJson(route('threads.agents.log', [$this->thread, 'aparent']))->assertNotFound();

    expect(stopCommands($this->thread))->toBe([]);
});

it('records an agent message and hands Claude the steering text', function () {
    $this->mock(ThreadMessageSender::class)->shouldReceive('send')->once()
        ->withArgs(fn (Thread $thread, User $user, ThreadMessageInput $input): bool => $thread->is($this->thread) && $user->is($this->owner) && $input->delivery === MessageDelivery::Steer && $input->text === 'Pass this to the agent Research agent (aparent): Also check the config.')
        ->andReturn(new ThreadMessageResult('steered', 'u-1', null));

    $this->actingAs($this->owner)->postJson(route('threads.agents.messages.store', [$this->thread, 'aparent']), ['message' => 'Also check the config.'])
        ->assertOk()->assertJsonPath('text', 'Also check the config.')->assertJsonPath('forwardedAt', null);

    $message = $this->parent->messages()->sole();
    expect($message->user_id)->toBe($this->owner->id)->and($message->forwarded_at)->toBeNull();
});

it('drops the recorded message when Claude cannot be handed it', function () {
    $this->mock(ThreadMessageSender::class)->shouldReceive('send')->once()->andThrow(ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.'));

    $this->actingAs($this->owner)->postJson(route('threads.agents.messages.store', [$this->thread, 'aparent']), ['message' => 'Also check the config.'])
        ->assertStatus(422)->assertJsonPath('message', 'This thread is closed.');

    expect($this->parent->messages()->count())->toBe(0);
});

it('refuses a message for an agent that has finished and an empty message', function () {
    $this->actingAs($this->owner)->postJson(route('threads.agents.messages.store', [$this->thread, 'adone']), ['message' => 'late'])->assertStatus(422);
    $this->actingAs($this->owner)->postJson(route('threads.agents.messages.store', [$this->thread, 'aparent']), ['message' => ''])->assertStatus(422)->assertJsonValidationErrors('message');

    expect(ThreadAgent::query()->where('thread_id', $this->thread->id)->has('messages')->count())->toBe(0);
});

it('marks the message forwarded once Claude sends it to the agent', function () {
    $this->parent->messages()->create(['user_id' => $this->owner->id, 'text' => 'Also check the config.']);
    config(['yak.threads.subagents' => true]);
    [$thread, $run] = [$this->thread, null];
    $line = ['type' => 'assistant', 'message' => ['id' => 'm1', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'SendMessage', 'input' => ['to' => 'aparent', 'message' => 'x']]]]];
    $events = [];

    app(ThreadAgents::class)->apply($thread, $run, $line, $events);
    app(ThreadAgents::class)->apply($thread, $run, [...$line, 'agent_id' => 'achild'], $events);

    expect($this->parent->messages()->sole()->forwarded_at)->not->toBeNull();
});

it('does not mark a message forwarded for a SendMessage an agent wrote', function () {
    $this->parent->messages()->create(['user_id' => $this->owner->id, 'text' => 'x']);
    $line = ['type' => 'assistant', 'agent_id' => 'achild', 'message' => ['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'SendMessage', 'input' => ['to' => 'aparent']]]]];
    $events = [];

    app(ThreadAgents::class)->apply($this->thread, null, $line, $events);

    expect($this->parent->messages()->sole()->forwarded_at)->toBeNull();
});

it('records a stop that the runner could not carry out', function () {
    $events = [];

    app(ThreadAgents::class)->apply($this->thread, null, ['type' => 'runner', 'event' => 'agent_stop_failed', 'taskId' => 'asibling', 'error' => 'No such task'], $events);
    app(ThreadAgents::class)->apply($this->thread, null, ['type' => 'runner', 'event' => 'agent_stop_failed', 'taskId' => 'unknown', 'error' => 'x'], $events);

    expect($this->sibling->refresh()->stop_error)->toBe('No such task')
        ->and($this->parent->refresh()->stop_error)->toBeNull();
});
