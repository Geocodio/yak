<?php

use App\Enums\TurnState;
use App\Events\ThreadSandboxSlotFreed;
use App\Jobs\StartChildThreadJob;
use App\Jobs\StopStackJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Stacks\ChildSpawner;
use App\Services\Stacks\StackActions;
use App\Services\Stacks\StopStack;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BusyRow;
use Tests\Support\StackWorld;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->withoutVite();
});

function runStopJob(Thread $root): void
{
    (new StopStackJob($root->id))->handle(app(StopStack::class));
}

it('stamps the stack and stops nothing in the request', function () {
    Queue::fake();
    $world = StackWorld::build();

    app(StackActions::class)->stop($world->working);

    expect($world->root->fresh()->stack_stopped_at)->not->toBeNull()
        ->and(TaskRun::query()->where('turn_state', TurnState::Running)->count())->toBe(1);
    Queue::assertPushed(StopStackJob::class, 1);
    Queue::assertPushed(StopStackJob::class, fn (StopStackJob $job): bool => $job->rootThreadId === $world->root->id);
});

it('dispatches nothing on a repeat once nothing runs', function () {
    $world = StackWorld::build();
    app(StackActions::class)->stop($world->root);
    $stamp = $world->root->fresh()->stack_stopped_at;

    Queue::fake();
    app(StackActions::class)->stop($world->root);

    expect(TaskRun::query()->where('turn_state', TurnState::Running)->exists())->toBeFalse()
        ->and($world->root->fresh()->stack_stopped_at->equalTo($stamp))->toBeTrue();
    Queue::assertNothingPushed();
});

it('lets a person stop again when the job failed every try', function () {
    Queue::fake();
    $world = StackWorld::build();
    $actions = app(StackActions::class);

    $actions->stop($world->root);
    Queue::assertPushed(StopStackJob::class, 1);

    $failing = Mockery::mock(StopStack::class);
    $failing->shouldReceive('stop')->andThrow(new RuntimeException('redis down'));
    $job = new StopStackJob($world->root->id);

    foreach (range(1, 3) as $attempt) {
        expect(fn () => $job->handle($failing))->toThrow(RuntimeException::class);
    }

    Log::spy();
    $job->failed(new RuntimeException('redis down'));
    Log::shouldHaveReceived('error')->once()->with('A stack could not be stopped', ['root' => $world->root->id, 'error' => RuntimeException::class]);

    $stamp = $world->root->fresh()->stack_stopped_at;
    expect(TaskRun::query()->where('turn_state', TurnState::Running)->count())->toBe(1);

    $actions->stop($world->root);
    Queue::assertPushed(StopStackJob::class, 2);
    expect($world->root->fresh()->stack_stopped_at->equalTo($stamp))->toBeTrue();

    runStopJob($world->root);
    expect(TaskRun::query()->where('turn_state', TurnState::Running)->exists())->toBeFalse();

    $actions->stop($world->root);
    Queue::assertPushed(StopStackJob::class, 2);
});

it('rolls the stamp back when the dispatch fails', function () {
    $world = StackWorld::build();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    app()->instance(Dispatcher::class, $dispatcher);

    expect(fn () => app(StackActions::class)->stop($world->root))->toThrow(RuntimeException::class);
    expect($world->root->fresh()->stack_stopped_at)->toBeNull();
});

it('stops every turn and updates the touched cards in the job', function () {
    $world = StackWorld::build();
    $queued = Thread::factory()->child($world->root)->create(['queued_for_slot_at' => now()]);
    app(StackActions::class)->stop($world->root);

    expect(TaskRun::query()->where('turn_state', TurnState::Running)->exists())->toBeFalse()
        ->and($queued->fresh()->queued_for_slot_at)->toBeNull()
        ->and(DB::table('thread_events')->where('thread_id', $world->root->id)->where('type', 'child')->count())->toBeGreaterThanOrEqual(2);

    runStopJob($world->root);

    expect(TaskRun::query()->where('turn_state', TurnState::Failed)->count())->toBe(0);
});

it('never starts a queued child after a stop', function () {
    $world = StackWorld::build();
    $queued = Thread::factory()->child($world->root)->create(['queued_for_slot_at' => now()]);
    app(StackActions::class)->stop($world->root);

    (new StartChildThreadJob($queued->id))->handle(app(ChildSpawner::class));
    event(new ThreadSandboxSlotFreed($world->root));

    expect($queued->fresh()->runs()->exists())->toBeFalse()
        ->and($queued->fresh()->sandbox_container)->toBeNull();
});

it('answers busy when the root lock wait runs out', function () {
    Queue::fake();
    $world = StackWorld::build();
    BusyRow::on(Thread::class, $world->root);

    expect(fn () => app(StackActions::class)->stop($world->root))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503));
    expect($world->root->fresh()->stack_stopped_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('still redirects on the web and stops in the job', function () {
    Queue::fake();
    $world = StackWorld::build();

    $this->actingAs($world->owner)->post(route('threads.stack.stop', $world->working))->assertRedirect();

    expect($world->root->fresh()->stack_stopped_at)->not->toBeNull();
    Queue::assertPushed(StopStackJob::class, 1);
});
