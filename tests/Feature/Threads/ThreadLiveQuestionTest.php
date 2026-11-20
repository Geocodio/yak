<?php

use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Events\ThreadEventBroadcast;
use App\Http\Resources\ThreadQuestionData;
use App\Jobs\Concerns\AsksClarifyingQuestions;
use App\Jobs\Concerns\GuardsTerminalTaskStatus;
use App\Jobs\RunYakJob;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use App\Services\Threads\ThreadLiveQuestions;
use App\Services\Threads\ThreadMessageSender;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryThreadEventWindow;

require_once __DIR__ . '/../../Support/LiveQuestionHelpers.php';

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

    expect($task->refresh()->status)->toBe(TaskStatus::Running)
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
    Queue::assertNothingPushed();
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
