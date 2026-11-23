<?php

use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadListFilter;
use App\Enums\TurnState;
use App\Events\ThreadEventBroadcast;
use App\Http\Resources\ThreadMessagesData;
use App\Http\Resources\ThreadQuestionData;
use App\Http\Resources\ThreadQueuedMessagesData;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\RunYakJob;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Push\ThreadPush;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\NeedsYouCount;
use App\Services\Threads\RunawayGuard;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadArtifacts;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use App\Services\Threads\ThreadList;
use App\Services\Threads\ThreadLiveQuestions;
use App\Services\Threads\ThreadMessageSender;
use App\Services\Threads\ThreadRequestStatus;
use App\YakPromptBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InMemoryThreadEventWindow;

require_once __DIR__ . '/../../Support/LiveQuestionHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/AppApiHelpers.php';

beforeEach(function () {
    Queue::fake();
    Event::fake([ThreadEventBroadcast::class]);
    app()->instance(ThreadEventWindow::class, new InMemoryThreadEventWindow);
    app()->forgetInstance(ThreadEventPublisher::class);
});

function runnerCommands(Thread $thread, string $type): array
{
    return collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->where('type', $type)->values()->all();
}

function liveQuestionEvents(Thread $thread): array
{
    return DB::table('thread_events')->where('thread_id', $thread->id)->where('type', 'question')->orderBy('sequence')
        ->pluck('payload')->map(fn (string $payload): array => json_decode($payload, true))->all();
}

it('turns a runner question line into the question prop and a question event', function () {
    [$thread, , $task] = threadWithRunningTurn();

    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    $question = ThreadQuestionData::for($thread);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->liveClarificationRound()['runner_question_id'])->toBe('rq-1')
        ->and($question['taskId'])->toBe($task->public_id)
        ->and($question['questions'])->toHaveCount(2)
        ->and($question['questions'][0])->toBe([
            'id' => 'q1',
            'header' => 'Screens',
            'question' => 'Which screens first?',
            'options' => [['label' => 'Contacts', 'description' => 'List and detail'], ['label' => 'Deals', 'description' => '']],
            'multiSelect' => true,
        ])
        ->and($question['questions'][1]['multiSelect'])->toBeFalse()
        ->and(liveQuestionEvents($thread))->toBe([['open' => true]]);
});

it('points the question push at the question card', function () {
    $push = Mockery::mock(ThreadPush::class);
    $push->shouldReceive('send')->once()->withArgs(fn ($event, $thread, $recipients, $body, $tag, $fragment): bool => $fragment === 'card-question');
    app()->instance(ThreadPush::class, $push);
    [$thread] = threadWithRunningTurn();

    questionLineFor($thread, [colourQuestion()]);
});

it('declines a question it cannot show so Claude never waits on it', function (array $questions) {
    [$thread, , $task] = threadWithRunningTurn();

    questionLineFor($thread, $questions);

    expect(runnerCommands($thread, 'decline_question'))->toHaveCount(1)
        ->and(runnerCommands($thread, 'decline_question')[0]['id'])->toBe('rq-1')
        ->and($task->refresh()->clarificationRounds())->toBe([])
        ->and(ThreadQuestionData::for($thread))->toBeNull();
})->with([
    'no questions' => [[]],
    'five questions' => [array_fill(0, 5, widthQuestion())],
    'one option' => [[['question' => 'Which?', 'header' => 'Pick', 'options' => [['label' => 'Only']], 'multiSelect' => false]]],
    'five options' => [[['question' => 'Which?', 'header' => 'Pick', 'options' => array_map(fn (int $number): array => ['label' => "Option {$number}"], range(1, 5)), 'multiSelect' => false]]],
]);

it('declines a second question while one is open', function () {
    [$thread] = threadWithRunningTurn();

    questionLineFor($thread, [widthQuestion()], 1, 'rq-1');
    questionLineFor($thread, [colourQuestion()], 2, 'rq-2');

    expect(runnerCommands($thread, 'decline_question'))->toHaveCount(1)
        ->and(runnerCommands($thread, 'decline_question')[0]['id'])->toBe('rq-2');
});

it('answers the runner from the web form without starting a turn', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['answers' => [
        'q1' => ['choices' => ['Contacts', 'Deals'], 'other' => null],
        'q2' => ['choices' => [], 'other' => 'Both, desktop first'],
    ]])->assertRedirect();

    $command = runnerCommands($thread, 'answer_question')[0];

    expect($command['id'])->toBe('rq-1')
        ->and((array) $command['answers'])->toBe(['Which screens first?' => 'Contacts, Deals', 'Which width?' => 'Both, desktop first'])
        ->and($task->refresh()->status)->toBe(TaskStatus::Running)
        ->and($task->liveClarificationRound())->toBeNull()
        ->and($task->clarificationRounds()[0]['answered_by'])->toBe('Test person')
        ->and(ThreadQuestionData::for($thread))->toBeNull()
        ->and(liveQuestionEvents($thread))->toBe([['open' => true], ['open' => false]]);
    Queue::assertNotPushed(RunYakJob::class);
});

it('tells Claude to decide when the person lets Claude decide', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['decide' => true])->assertRedirect();

    expect(runnerCommands($thread, 'decline_question')[0]['message'])->toBe(ThreadLiveQuestions::DECIDE_MESSAGE)
        ->and($task->refresh()->liveClarificationRound())->toBeNull()
        ->and(liveQuestionEvents($thread))->toBe([['open' => true], ['open' => false]]);
});

it('answers a single question from a composer message that matches an option', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    app(ThreadMessageSender::class)->send($thread, $user, new ThreadMessageInput('laptop', null, null, null, MessageDelivery::Steer, null));

    expect((array) runnerCommands($thread, 'answer_question')[0]['answers'])->toBe(['Which width?' => 'Laptop'])
        ->and($task->refresh()->liveClarificationRound())->toBeNull()
        ->and(runnerCommands($thread, 'steer'))->toBe([]);
});

it('sends a composer message to Claude in words when it cannot match one question', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    app(ThreadMessageSender::class)->send($thread, $user, new ThreadMessageInput('Do contacts first', null, null, null, MessageDelivery::Steer, null));

    expect(runnerCommands($thread, 'decline_question')[0]['message'])->toBe('The person replied instead of picking options: Do contacts first')
        ->and($task->refresh()->liveClarificationRound())->toBeNull();
});

it('answers the runner from the app API', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->postJson(appThreadPath($thread, '/clarification-answers'), ['answers' => ['q1' => ['choices' => ['Desktop'], 'other' => null]]], phoneHeadersFor($user))
        ->assertOk()->assertExactJson(['status' => 'sent']);

    expect((array) runnerCommands($thread, 'answer_question')[0]['answers'])->toBe(['Which width?' => 'Desktop'])
        ->and($task->refresh()->status)->toBe(TaskStatus::Running);

    questionLineFor($thread, [widthQuestion()], 2, 'rq-2');
    $this->postJson(appThreadPath($thread, '/clarification-answers'), ['decide' => true], phoneHeadersFor($user))->assertOk();

    expect(runnerCommands($thread, 'decline_question')[0]['id'])->toBe('rq-2');
});

it('keeps the app question shape for a live question', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->getJson(appThreadPath($thread), phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('question.taskId', $task->public_id)
        ->assertJsonPath('question.expiresAt', null)
        ->assertJsonPath('question.questions.0.id', 'q1')
        ->assertJsonPath('question.questions.0.options.0.label', 'Desktop');
});

it('pauses the step and wall-clock guards while a question is open', function () {
    config(['yak.threads.question_wait_minutes' => 10_000]);
    [$thread, $run, $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->travel(241)->minutes();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running);

    $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['answers' => ['q1' => ['choices' => ['Desktop'], 'other' => null]]]);
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running)
        ->and(ThreadLiveQuestions::waitedSeconds($run, now()))->toBe(241 * 60);

    $this->travel(241)->minutes();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::StoppedByGuard);
});

it('does not count the time of a question that timed out', function () {
    [$thread, $run, $task] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->travel(31)->minutes();
    app(ThreadDriver::class)->tick();
    $this->travel(220)->minutes();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running)
        ->and(ThreadLiveQuestions::waitedSeconds($run, now()))->toBe(31 * 60);
});

it('tells Claude to stop after 30 minutes without an answer and parks the question when the turn ends', function () {
    [$thread, $run, $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->travel(29)->minutes();
    app(ThreadDriver::class)->tick();
    expect(runnerCommands($thread, 'decline_question'))->toBe([]);

    $this->travel(2)->minutes();
    app(ThreadDriver::class)->tick();

    $command = runnerCommands($thread, 'decline_question')[0];
    $task->refresh();

    expect($command['message'])->toBe(ThreadLiveQuestions::TIMEOUT_MESSAGE)
        ->and($task->liveClarificationRound())->toBeNull()
        ->and($task->clarificationRounds()[0]['answers'])->toBeNull()
        ->and(ThreadLiveQuestions::isParkPending($task))->toBeTrue();

    $job = new class($task)
    {
        use AsksClarifyingQuestions, GuardsTerminalTaskStatus;

        public function __construct(public YakTask $task) {}

        public function finish(): bool
        {
            $parked = $this->askIfNeeded(new AgentRunResult('s', 'Stopped.', 0.0, 1, 1, false, ''));
            $this->finishParkingForQuestions();

            return $parked;
        }
    };

    expect($job->finish())->toBeTrue();

    $task->refresh();
    $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['answers' => ['q1' => ['choices' => ['Laptop'], 'other' => null]]])->assertRedirect();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and(ThreadLiveQuestions::isParkPending($task))->toBeFalse()
        ->and($task->refresh()->clarificationRounds()[0]['answers']['q1']['choices'])->toBe(['Laptop'])
        ->and(runnerCommands($thread, 'answer_question'))->toBe([]);
    Queue::assertPushed(RunYakJob::class);
});

it('drops the question when its turn fails or the runner goes away', function (string $how) {
    [$thread, $run, $task] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    if ($how === 'runner closed') {
        app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'runner', 'event' => 'question_closed', 'id' => 'rq-1', 'reason' => 'aborted']));
    } elseif ($how === 'runner exit') {
        app()->instance(ThreadCommands::class, Mockery::mock(ThreadCommands::class)->shouldIgnoreMissing());
        app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1, 'exit_code' => 1], fromBroker: true));
    } else {
        app(ThreadDriver::class)->failTurnOf(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 2, ['type' => 'runner', 'event' => 'error']));
    }

    expect($task->refresh()->liveClarificationRound())->toBeNull()
        ->and(ThreadQuestionData::for($thread))->toBeNull()
        ->and(liveQuestionEvents($thread)[1] ?? null)->toBe(['open' => false]);
})->with(['runner closed', 'runner exit', 'turn failed']);

it('leaves the text-fence contract out of thread prompts and keeps it when the tool is off', function () {
    config(['yak.threads.ask_user_question' => true]);
    $withTool = YakPromptBuilder::followUpPrompt('Please add a test.');

    config(['yak.threads.ask_user_question' => false]);
    $withFence = YakPromptBuilder::followUpPrompt('Please add a test.');

    expect($withTool)->toContain('AskUserQuestion')->not->toContain('```clarification')
        ->and($withFence)->toContain('```clarification')->not->toContain('AskUserQuestion');
});

it('still enforces the step limit while a question is open', function () {
    config(['yak.max_turns' => 300]);
    [$thread, $run] = threadWithRunningTurn();
    $run->update(['driver_user_id' => null, 'source' => 'sentry']);
    questionLineFor($thread, [widthQuestion()]);

    expect((new RunawayGuard)->check($run->refresh(), CarbonImmutable::now(), 299))->toBeNull()
        ->and((new RunawayGuard)->check($run, CarbonImmutable::now(), 300))->toBe(RunawayGuard::ASSISTANT_TURNS);
});

it('declines the sixth question of one turn so waiting cannot be extended without end', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();

    foreach (range(1, 5) as $number) {
        questionLineFor($thread, [widthQuestion()], $number, "rq-{$number}");
        $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['answers' => ['q1' => ['choices' => ['Desktop'], 'other' => null]]]);
    }
    questionLineFor($thread, [widthQuestion()], 6, 'rq-6');

    $declined = runnerCommands($thread, 'decline_question');

    expect($declined)->toHaveCount(1)
        ->and($declined[0]['id'])->toBe('rq-6')
        ->and($declined[0]['message'])->toBe(ThreadLiveQuestions::TOO_MANY_MESSAGE)
        ->and($task->refresh()->liveClarificationRound())->toBeNull()
        ->and($task->clarificationRoundCount())->toBe(5);
});

it('counts a live question as needing the person until it is answered', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    $needsYou = fn () => collect(ThreadList::for($user, ThreadListFilter::Mine)['needsYou'])->firstWhere('id', $thread->public_id);

    expect($needsYou())->toBeNull()
        ->and(ThreadRequestStatus::for($thread)['key'])->toBe('working');

    questionLineFor($thread, [widthQuestion()]);

    expect($needsYou())->not->toBeNull()
        ->and($needsYou()['needsYouReason'])->toBe('question')
        ->and(NeedsYouCount::for($user))->toBe(1)
        ->and(ThreadRequestStatus::for($thread)['key'])->toBe('needs_you');

    $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['answers' => ['q1' => ['choices' => ['Desktop'], 'other' => null]]]);

    expect($needsYou())->toBeNull()
        ->and(NeedsYouCount::for($user))->toBe(0)
        ->and(ThreadRequestStatus::for($thread)['key'])->toBe('working');
});

it('keeps a question that timed out counted as needing the person while it is parked', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->travel(31)->minutes();
    app(ThreadDriver::class)->tick();

    expect(NeedsYouCount::for($user))->toBe(1)
        ->and(ThreadRequestStatus::for($thread)['key'])->toBe('needs_you');
});

it('replays the recorded SDK stream of a two-question call', function () {
    [$thread, , $task] = threadWithRunningTurn();

    replayRunnerFixture($thread, 'ask-user-question');

    // The recording ends with the question answered, so the round is the one the runner asked.
    $round = $task->refresh()->clarificationRounds()[0];

    expect($round['questions'])->toHaveCount(2)
        ->and($round['questions'][0]['multi_select'])->toBeTrue()
        ->and($round['questions'][0]['question'])->toBe('Which fruits?')
        ->and($round['questions'][1]['options'])->toHaveCount(2)
        ->and($round['live'])->toBeTrue();
});

function answerMessages(Thread $thread, User $user): array
{
    return collect(ThreadMessagesData::for($thread, $user))->where('authorName', 'Test person')->values()->all();
}

it('shows the answers of a live question in the conversation where they were given', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['answers' => [
        'q1' => ['choices' => ['Contacts', 'Deals'], 'other' => null],
        'q2' => ['choices' => [], 'other' => 'Both, desktop first'],
    ]]);

    $message = answerMessages($thread, $user)[0];

    expect($message['text'])->toBe("**Screens**: Which screens first?\nContacts, Deals\n\n**Width**: Which width?\nBoth, desktop first")
        ->and($message['state'])->toBe('read')
        ->and($message['readAfterSequence'])->not->toBeNull();
});

it('shows that the person asked Claude to decide', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->actingAs($user)->post(route('threads.clarification-answers.store', $thread), ['decide' => true]);

    expect(answerMessages($thread, $user)[0]['text'])->toBe('Asked Claude to decide');
});

it('shows the answer given in the composer', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);
    app(ThreadMessageSender::class)->send($thread, $user, new ThreadMessageInput('laptop', null, null, null, MessageDelivery::Steer, null));
    questionLineFor($thread, [colourQuestion(), widthQuestion()], 2, 'rq-2');
    app(ThreadMessageSender::class)->send($thread, $user, new ThreadMessageInput('Do contacts first', null, null, null, MessageDelivery::Steer, null));

    expect(collect(answerMessages($thread, $user))->pluck('text')->all())->toBe(["**Width**: Which width?\nLaptop", 'Do contacts first']);
});

it('shows the answer given through the app API', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->postJson(appThreadPath($thread, '/clarification-answers'), ['answers' => ['q1' => ['choices' => ['Desktop'], 'other' => null]]], phoneHeadersFor($user))->assertOk();

    expect(answerMessages($thread, $user)[0]['text'])->toBe("**Width**: Which width?\nDesktop");
});

it('keeps the files sent with a composer answer and steers them to Claude right after the answer', function () {
    Storage::fake('artifacts');
    [$thread, , $task, $user] = threadWithRunningTurn();
    $thread->forceFill(['runner_state' => RunnerState::Busy])->save();
    questionLineFor($thread, [widthQuestion()]);

    $this->actingAs($user)->post(route('threads.messages.store', $thread), [
        'message' => 'laptop',
        'attachments' => [UploadedFile::fake()->image('shot.png')],
        'attachment_refs' => ['Image #1'],
    ], ['Accept' => 'application/json'])->assertOk();

    $steer = runnerCommands($thread, 'steer');
    $attachment = TaskAttachment::query()->sole();

    expect((array) runnerCommands($thread, 'answer_question')[0]['answers'])->toBe(['Which width?' => 'Laptop'])
        ->and($steer)->toHaveCount(1)
        ->and($steer[0]['text'])->toContain('laptop')
        ->and($attachment->pending_steering_message_id)->not->toBeNull()
        ->and(PendingSteeringMessage::query()->find($attachment->pending_steering_message_id)->mode)->toBe(SteeringMode::Steer);
});

it('keeps the question visible after the timeout and delivers an answer through the parked path when the turn ends', function () {
    [$thread, , $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->travel(31)->minutes();
    app(ThreadDriver::class)->tick();

    expect(ThreadQuestionData::for($thread))->not->toBeNull();

    app(ThreadMessageSender::class)->send($thread, $user, new ThreadMessageInput('laptop', null, null, null, MessageDelivery::Steer, null));
    $task->refresh();

    expect(ThreadQuestionData::for($thread))->toBeNull()
        ->and(runnerCommands($thread, 'answer_question'))->toBe([])
        ->and($task->clarificationAnswersAwaitingResume()['answers']['q1']['choices'])->toBe(['Laptop']);
    Queue::assertNotPushed(RunYakJob::class);

    $job = new class($task)
    {
        use AsksClarifyingQuestions, GuardsTerminalTaskStatus;

        public function __construct(public YakTask $task) {}

        public function finish(): bool
        {
            $parked = $this->askIfNeeded(new AgentRunResult('s', 'Stopped.', 0.0, 1, 1, false, ''));
            $this->finishParkingForQuestions();

            return $parked;
        }
    };

    expect($job->finish())->toBeTrue();
    expect($task->refresh()->status)->toBe(TaskStatus::Pending)
        ->and(ThreadLiveQuestions::hasParkMark($task))->toBeFalse();
    Queue::assertPushed(RunYakJob::class);
});

it('queues the answer given after the timeout for the next prompt when the turn does not complete', function () {
    [$thread, $run, $task, $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);

    $this->travel(31)->minutes();
    app(ThreadDriver::class)->tick();
    app(ThreadMessageSender::class)->send($thread, $user, new ThreadMessageInput('laptop', null, null, null, MessageDelivery::Steer, null));

    app(ThreadLiveQuestions::class)->settleAtTurnEnd($run, TurnState::Failed);

    expect(PendingSteeringMessage::query()->sole()->text)->toContain('Laptop')
        ->and($task->refresh()->clarificationAnswersAwaitingResume())->toBeNull();
});

it('queues the files of an answer that Claude cannot take now, and shows them like any queued message', function () {
    Storage::fake('artifacts');
    [$thread, , , $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);
    $this->mock(SteeringDelivery::class)->shouldReceive('deliver')->andReturn(false);

    $this->actingAs($user)->post(route('threads.messages.store', $thread), [
        'message' => 'laptop',
        'attachments' => [UploadedFile::fake()->image('shot.png')],
        'attachment_refs' => ['Image #1'],
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJson(['status' => 'queued', 'notice' => 'Your files go to Claude with the next message.']);

    $queued = PendingSteeringMessage::query()->sole();
    $attachment = TaskAttachment::query()->sole();

    expect($queued->mode)->toBe(SteeringMode::Queue)
        ->and($queued->text)->toContain('laptop')
        ->and($attachment->pending_steering_message_id)->toBe($queued->id)
        ->and(ThreadQueuedMessagesData::for($thread, $user))->toHaveCount(1)
        ->and(ThreadQueuedMessagesData::for($thread, $user)[0]['attachments'])->toHaveCount(1);

    $row = collect(ThreadMessagesData::for($thread, $user))->firstWhere('id', 'queued-message-' . $queued->id);

    expect($row['mode'])->toBe('queue')->and($row['attachments'])->toHaveCount(1);
});

it('tells the app that the files of an answer are queued', function () {
    Storage::fake('artifacts');
    [$thread, , , $user] = threadWithRunningTurn();
    questionLineFor($thread, [widthQuestion()]);
    $this->mock(SteeringDelivery::class)->shouldReceive('deliver')->andReturn(false);

    $this->postJson(appThreadPath($thread, '/messages'), [
        'message' => 'laptop',
        'attachments' => [UploadedFile::fake()->image('shot.png')],
        'attachment_refs' => ['Image #1'],
        'send_key' => (string) Str::uuid(),
    ], phoneHeadersFor($user))
        ->assertOk()
        ->assertJson(['status' => 'queued', 'notice' => 'Your files go to Claude with the next message.']);

    $queued = PendingSteeringMessage::query()->sole();
    $row = collect(ThreadMessagesData::for($thread, $user))->firstWhere('id', 'queued-message-' . $queued->id);

    expect($row['attachments'])->toHaveCount(1);
});

function flowQuestion(): array
{
    return [
        'question' => "Which flow?\n\n![Flow today](flow.png)",
        'header' => 'Flow',
        'options' => [['label' => 'Mirror', 'description' => 'Same as owned'], ['label' => 'Strict', 'description' => 'Refuse']],
        'multiSelect' => false,
    ];
}

it('collects shared files before recording a question that references one', function () {
    $artifacts = Mockery::spy(ThreadArtifacts::class);
    app()->instance(ThreadArtifacts::class, $artifacts);
    [$thread, , $task] = threadWithRunningTurn();

    questionLineFor($thread, [flowQuestion()]);

    $artifacts->shouldHaveReceived('collectShared')->once()->with(Mockery::on(fn (Thread $given): bool => $given->is($thread)));
    expect($task->refresh()->liveClarificationRound())->not->toBeNull();
});

it('does not collect when no question references a file', function () {
    $artifacts = Mockery::spy(ThreadArtifacts::class);
    app()->instance(ThreadArtifacts::class, $artifacts);
    [$thread] = threadWithRunningTurn();

    questionLineFor($thread, [colourQuestion()]);

    $artifacts->shouldNotHaveReceived('collectShared');
});

it('opens the question when collecting shared files fails', function () {
    $artifacts = Mockery::mock(ThreadArtifacts::class);
    $artifacts->shouldReceive('collectShared')->andThrow(new RuntimeException('sandbox gone'));
    app()->instance(ThreadArtifacts::class, $artifacts);
    [$thread, , $task] = threadWithRunningTurn();

    questionLineFor($thread, [flowQuestion()]);

    expect($task->refresh()->liveClarificationRound())->not->toBeNull()
        ->and(collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->firstWhere('type', 'decline_question'))->toBeNull();
});
