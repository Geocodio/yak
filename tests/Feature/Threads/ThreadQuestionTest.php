<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadListFilter;
use App\Events\ThreadEventBroadcast;
use App\Http\Resources\ThreadQuestionData;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClarificationAnswerSubmitter;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use App\Services\Threads\ThreadList;
use App\Services\Threads\ThreadMessageSender;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryThreadEventWindow;

beforeEach(function () {
    Queue::fake();
    Event::fake([ThreadEventBroadcast::class]);
    app()->instance(ThreadEventWindow::class, new InMemoryThreadEventWindow);
    app()->forgetInstance(ThreadEventPublisher::class);
});

function questionOnFailure(): ClarificationQuestion
{
    return ClarificationQuestion::fromArray([
        'id' => 'on_failure',
        'header' => 'On failure',
        'question' => 'When all retries fail, what should happen to the row?',
        'options' => [
            ['label' => 'Mark the row as failed', 'description' => 'Keep the batch going.'],
            ['label' => 'Fail the whole batch', 'description' => 'Stop at the first failure.'],
        ],
        'multi_select' => false,
    ]);
}

/**
 * @return array{0: Thread, 1: YakTask}
 */
function threadAwaitingAnswer(): array
{
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create([
        'thread_id' => $thread->id,
        'status' => TaskStatus::AwaitingClarification,
        'clarification_expires_at' => Carbon::parse('2026-11-16 12:00:00', 'UTC'),
    ]);
    $task->recordClarificationRound([questionOnFailure()], 'I need one decision.');

    return [$thread, $task];
}

function questionEvents(Thread $thread): array
{
    return DB::table('thread_events')->where('thread_id', $thread->id)->where('type', 'question')->orderBy('sequence')
        ->pluck('payload')->map(fn (string $payload): array => json_decode($payload, true))->all();
}

it('shows the open question in camelCase', function () {
    [$thread, $task] = threadAwaitingAnswer();

    expect(ThreadQuestionData::for($thread))->toBe([
        'taskId' => $task->public_id,
        'expiresAt' => '2026-11-16T12:00:00+00:00',
        'questions' => [[
            'id' => 'on_failure',
            'header' => 'On failure',
            'question' => 'When all retries fail, what should happen to the row?',
            'options' => [
                ['label' => 'Mark the row as failed', 'description' => 'Keep the batch going.'],
                ['label' => 'Fail the whole batch', 'description' => 'Stop at the first failure.'],
            ],
            'multiSelect' => false,
        ]],
    ]);
});

it('has no question when the round is answered, when an older task asks, or when the thread has no tasks', function () {
    [$thread, $task] = threadAwaitingAnswer();
    YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Running]);
    expect(ThreadQuestionData::for($thread))->toBeNull();

    [$answeredThread, $answeredTask] = threadAwaitingAnswer();
    $answeredTask->recordClarificationAnswers(['on_failure' => ['choices' => ['Fail the whole batch'], 'other' => null]], null, 'Test person');
    expect(ThreadQuestionData::for($answeredThread))->toBeNull();

    expect(ThreadQuestionData::for(Thread::factory()->create()))->toBeNull();
});

it('emits question open when a job parks for questions and closed when answered', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Running]);
    $task->recordClarificationRound([questionOnFailure()], 'I need one decision.');

    $job = new class($task)
    {
        use AsksClarifyingQuestions, GuardsTerminalTaskStatus;

        public function __construct(public YakTask $task) {}

        public function park(): void
        {
            $this->parkedForQuestions = true;
            $this->finishParkingForQuestions();
        }
    };
    $job->park();

    expect(questionEvents($thread))->toBe([['open' => true]]);

    $submitted = app(ClarificationAnswerSubmitter::class)->submit($task->fresh(), ['on_failure' => ['choices' => ['Fail the whole batch'], 'other' => null]], null, 'Test person', 'dashboard');

    expect($submitted)->toBeTrue()
        ->and(questionEvents($thread))->toBe([['open' => true], ['open' => false]]);
});

it('emits closed when a round expires', function () {
    [$thread, $task] = threadAwaitingAnswer();
    $task->update(['clarification_expires_at' => now()->subMinute()]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    expect(questionEvents($thread))->toBe([['open' => false]]);
});

it('emits closed when the waiting task is cancelled', function () {
    [$thread, $task] = threadAwaitingAnswer();

    $task->update(['status' => TaskStatus::Cancelled]);

    expect(questionEvents($thread))->toBe([['open' => false]])
        ->and(ThreadQuestionData::for($thread))->toBeNull();
});

it('emits nothing when an older task leaves the status', function () {
    [$thread, $older] = threadAwaitingAnswer();
    YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Running]);

    $older->update(['status' => TaskStatus::Pending]);

    expect(questionEvents($thread))->toBe([]);
});

it('closes the question when the person answers in the composer', function () {
    [$thread, $task] = threadAwaitingAnswer();
    $thread->update(['claude_account_id' => ClaudeAccount::factory()->create()->id]);
    $person = User::factory()->create(['name' => 'Test person']);
    $task->update(['clarification_reminder_at' => now()->addDay()]);

    expect(collect(ThreadList::for($person, ThreadListFilter::Everyone)['needsYou'])->firstWhere('id', $thread->public_id))->not->toBeNull();

    app(ThreadMessageSender::class)->send($thread, $person, new ThreadMessageInput('Use the staging bucket', null, null, null, MessageDelivery::Steer, null));

    $task->refresh();
    $needsYou = collect(ThreadList::for($person, ThreadListFilter::Everyone)['needsYou'])->firstWhere('id', $thread->public_id);

    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->clarification_expires_at)->toBeNull()
        ->and($task->clarification_reminder_at)->toBeNull()
        ->and($task->clarificationAnswersAwaitingResume())->toBeNull()
        ->and($task->clarificationRounds()[0]['note'])->toBe('Use the staging bucket')
        ->and($task->clarificationRounds()[0]['answered_by'])->toBe('Test person')
        ->and($needsYou)->toBeNull()
        ->and(ThreadQuestionData::for($thread))->toBeNull()
        ->and(questionEvents($thread)[0]['open'] ?? null)->toBeFalse();
});

it('keeps the question open when an automated turn starts', function () {
    [$thread, $task] = threadAwaitingAnswer();
    $thread->update(['claude_account_id' => ClaudeAccount::factory()->create()->id]);

    app(ThreadTurns::class)->start($task, 'Continue.', User::factory()->create(), null, TaskRunKind::RepositoryEvent);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingClarification)
        ->and(questionEvents($thread))->toBe([])
        ->and(ThreadQuestionData::for($thread))->not->toBeNull();
});

it('emits nothing for a task without a thread', function () {
    $task = YakTask::factory()->create(['thread_id' => null, 'status' => TaskStatus::AwaitingClarification]);

    $task->update(['status' => TaskStatus::Cancelled]);

    expect(DB::table('thread_events')->count())->toBe(0);
});

it('keeps answering when the event cannot be stored', function () {
    [$thread, $task] = threadAwaitingAnswer();
    $this->mock(ThreadEventPublisher::class, fn ($publisher) => $publisher->shouldReceive('append')->andThrow(new RuntimeException('window down')));
    Exceptions::fake();

    $submitted = app(ClarificationAnswerSubmitter::class)->submit($task, ['on_failure' => ['choices' => ['Fail the whole batch'], 'other' => null]], null, 'Test person', 'dashboard');

    expect($submitted)->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::Pending);
    Exceptions::assertReported(RuntimeException::class);
});

it('stores the close event only after the surrounding transaction commits', function () {
    [$thread, $task] = threadAwaitingAnswer();

    DB::transaction(function () use ($task, $thread) {
        $task->update(['status' => TaskStatus::Cancelled]);
        expect(questionEvents($thread))->toBe([]);
    });

    expect(questionEvents($thread))->toBe([['open' => false]]);
});

it('stores the close event for a signed-in person who cannot see the thread', function () {
    $thread = Thread::factory()->private()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::AwaitingClarification]);
    $task->recordClarificationRound([questionOnFailure()], 'I need one decision.');
    $this->actingAs(User::factory()->create());

    $task->update(['status' => TaskStatus::Cancelled]);

    expect(questionEvents($thread))->toBe([['open' => false]]);
});
