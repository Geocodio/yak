<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\TaskStatus;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClarificationAnswerSubmitter;
use App\Services\Threads\ThreadEventProjector;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

/**
 * @return array{0: Thread, 1: YakTask}
 */
function awaitingQuestionThread(): array
{
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::AwaitingClarification]);
    $task->recordClarificationRound([ClarificationQuestion::fromArray([
        'id' => 'on_failure',
        'header' => 'On failure',
        'question' => 'What should happen to the row?',
        'options' => [['label' => 'Mark failed', 'description' => 'Keep going.'], ['label' => 'Fail the batch', 'description' => 'Stop.']],
        'multi_select' => false,
    ])], 'I need one decision.');

    return [$thread, $task];
}

it('gives the thread page the open question while the head task awaits clarification, and null after answering', function () {
    Queue::fake();
    [$thread, $task] = awaitingQuestionThread();
    $this->actingAs(User::factory()->create());

    $this->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('question.taskId', $task->public_id)
        ->where('question.questions.0.id', 'on_failure')
        ->where('question.questions.0.multiSelect', false)
        ->has('question.questions.0.options', 2));

    app(ClarificationAnswerSubmitter::class)->submit($task, ['on_failure' => ['choices' => ['Mark failed'], 'other' => null]], 'Keep an eye on it', 'Ana', 'dashboard');

    $this->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page->where('question', null));
});

it('shows the answers as one line per question, then the note', function () {
    Queue::fake();
    [, $task] = awaitingQuestionThread();
    app(ClarificationAnswerSubmitter::class)->submit($task, ['on_failure' => ['choices' => ['Mark failed'], 'other' => null]], 'Keep an eye on it', 'Ana', 'dashboard');

    expect(YakPromptBuilder::clarificationAnswersShownText($task->fresh()))->toBe("**On failure**: Mark failed\n\nKeep an eye on it");
});

it('strips the clarification block from the message only when it parses as questions', function () {
    $json = '{"questions":[{"id":"a","header":"H","question":"Q?","options":[{"label":"One","description":"d"},{"label":"Two","description":"d"}],"multi_select":false}]}';
    $projector = new ThreadEventProjector;

    $asked = $projector->withHtml(['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => "I need a decision.\n\n```clarification\n{$json}\n```"]]]);
    $plain = $projector->withHtml(['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => "Config:\n\n```json\n{$json}\n```\n\n```clarification\nnot json\n```"]]]);

    expect($asked['blocks'][0]['html'])->toContain('I need a decision.')->not->toContain('clarification')->not->toContain('One')
        ->and($asked['blocks'][0]['text'])->toBe('I need a decision.')
        ->and($plain['blocks'][0]['html'])->toContain('language-json', 'language-clarification');
});
