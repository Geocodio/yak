<?php

use App\Enums\TurnState;
use App\Http\Resources\ThreadComposerData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Enums\ThreadVisibility;
use App\Services\Threads\RunawayGuard;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['yak.max_turns' => 300]);
    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->create(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $this->owner->id]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'source' => 'sentry']);
    $this->run = TaskRun::factory()->create(['yak_task_id' => $this->task->id, 'thread_id' => $this->thread->id, 'turn_state' => TurnState::Running, 'driver_user_id' => null, 'source' => 'sentry', 'assistant_turns' => 262]);
});

it('adds the configured steps to the running turn of a limited thread', function () {
    $this->actingAs($this->owner)->post(route('threads.step-limit.store', $this->thread))->assertRedirect();

    expect($this->run->refresh()->extra_turns)->toBe(300)
        ->and(RunawayGuard::turnLimit($this->run))->toBe(600)
        ->and((new RunawayGuard)->check($this->run, CarbonImmutable::now(), 299 + 300))->toBeNull();
});

it('refuses a turn a person started, which has no step limit', function () {
    $this->run->update(['driver_user_id' => $this->owner->id]);

    $this->actingAs($this->owner)->post(route('threads.step-limit.store', $this->thread))->assertSessionHas('error');
    expect($this->run->refresh()->extra_turns)->toBe(0);
});

it('does nothing when no turn runs', function () {
    $this->run->update(['turn_state' => TurnState::Completed]);

    $this->actingAs($this->owner)->post(route('threads.step-limit.store', $this->thread))->assertSessionHas('error');
    expect($this->run->refresh()->extra_turns)->toBe(0);
});

it('is closed to a person who cannot drive the thread and a stranger', function () {
    $this->actingAs(User::factory()->create())->post(route('threads.step-limit.store', $this->thread))->assertNotFound();
    expect($this->run->refresh()->extra_turns)->toBe(0);
});

it('hands the composer the steps taken and the limit of a running turn', function () {
    expect(ThreadComposerData::for($this->thread, $this->owner)['turn'])->toBe(['steps' => 262, 'limit' => 300, 'canAddSteps' => true, 'stepsToAdd' => 300]);

    $this->run->update(['driver_user_id' => $this->owner->id]);
    expect(ThreadComposerData::for($this->thread, $this->owner)['turn'])->toBe(['steps' => 262, 'limit' => null, 'canAddSteps' => false, 'stepsToAdd' => 300]);

    $this->run->update(['turn_state' => TurnState::Completed]);
    expect(ThreadComposerData::for($this->thread, $this->owner)['turn'])->toBeNull();
});
