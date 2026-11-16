<?php

use App\DataTransferObjects\YakToolCall;
use App\Enums\RepositoryRole;
use App\Enums\StackThreadMessageKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\StartChildThreadJob;
use App\Models\PendingSteeringMessage;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Stacks\StackResultBatcher;
use App\Services\Stacks\StackToolRouter;
use App\Services\Stacks\StopStack;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

it('walks spawn, batched results, a failed child and Stop stack against fakes', function () {
    Mail::fake();
    Queue::fake();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('steer', 'queue', 'kill', 'interrupt')->andReturn(true)->byDefault();
    [$parent, $parentTask] = threadWithTask();
    $parent->update(['branch' => 'yak/parent']);
    ThreadRepository::factory()->create(['thread_id' => $parent->id, 'repo' => $parent->repo, 'role' => RepositoryRole::Primary]);
    TaskRun::factory()->create(['thread_id' => $parent->id, 'yak_task_id' => $parentTask->id, 'turn_state' => TurnState::Running]);
    $router = app(StackToolRouter::class);

    // Spawn two research children and one that will fail.
    foreach (['alpha', 'beta', 'gamma'] as $name) {
        $result = $router->handle($parent, new YakToolCall('c-' . $name, 'spawn_thread', ['name' => $name, 'brief' => "Look into {$name}.", 'kind' => 'research']));
        expect($result->isError)->toBeFalse();
    }
    Queue::assertPushed(StartChildThreadJob::class, 3);
    $children = Thread::query()->where('parent_thread_id', $parent->id)->orderBy('id')->get()->keyBy('name');
    expect($children)->toHaveCount(3)
        ->and($router->handle($parent, new YakToolCall('c-list', 'list_threads', []))->content)->toContain('alpha')->toContain('gamma');

    $runFor = function (Thread $child, TurnState $state, string $result = '') {
        $task = $child->tasks()->first() ?? YakTask::factory()->create(['thread_id' => $child->id]);

        return TaskRun::factory()->create(['thread_id' => $child->id, 'yak_task_id' => $task->id, 'turn_state' => $state, 'last_result_event' => ['result' => $result]]);
    };

    // Two results land while gamma still runs: nothing is delivered yet.
    $gamma = $runFor($children['gamma'], TurnState::Running);
    event(new TurnCompleted($runFor($children['alpha'], TurnState::Completed, 'Alpha found X.')));
    event(new TurnCompleted($runFor($children['beta'], TurnState::Completed, 'Beta found Y.')));
    expect(PendingSteeringMessage::query()->count())->toBe(0);

    // A failure goes to the parent at once.
    $gamma->update(['turn_state' => TurnState::Failed]);
    event(new TurnFailed($gamma));
    $failure = StackThreadMessage::query()->where('kind', StackThreadMessageKind::Failure)->sole();
    expect($failure->delivered_at)->not->toBeNull()
        ->and(PendingSteeringMessage::query()->pluck('text')->implode("\n"))->toContain('Sub-thread gamma');

    // The scheduler tick delivers both results as one message, and a second tick adds nothing.
    $batcher = app(StackResultBatcher::class);
    expect($batcher->deliverDue(now()->toImmutable()))->toBe(1)
        ->and($batcher->deliverDue(now()->toImmutable()))->toBe(0);
    $batched = PendingSteeringMessage::query()->orderByDesc('id')->first()->text;
    expect($batched)->toContain('Alpha found X.')->toContain('Beta found Y.');

    // Stop stack interrupts the parent, closes nothing and blocks new spawns.
    app(StopStack::class)->stop($parent);

    $after = $router->handle($parent->fresh(), new YakToolCall('c-late', 'spawn_thread', ['name' => 'late', 'brief' => 'b', 'kind' => 'research']));
    expect($parent->fresh()->stack_stopped_at)->not->toBeNull()
        ->and($after->isError)->toBeTrue()
        ->and(Thread::query()->where('parent_thread_id', $parent->id)->count())->toBe(3);
});
