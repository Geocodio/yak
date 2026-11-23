<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClarificationAnswerSubmitter;
use App\Services\ThreadBuilder;
use App\Services\Threads\ThreadEventProjector;
use App\Services\Threads\ThreadLiveQuestions;
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

/**
 * @return array{0: Thread, 1: YakTask}
 */
function attachmentQuestionThread(string $question = "How should 3DS work?\n\n![Flow today](flow.png)"): array
{
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::AwaitingClarification]);
    $task->recordClarificationRound([ClarificationQuestion::fromArray([
        'id' => 'q1',
        'header' => '3DS',
        'question' => $question,
        'options' => [
            ['label' => 'Mirror', 'description' => 'Same as owned.', 'preview' => "![Inline mockup](.yak-artifacts/shared/inline.html)\n\nApplies the plan."],
            ['label' => 'Strict', 'description' => 'Refuse.'],
        ],
    ])], '');

    return [$thread, $task];
}

it('shows context and option attachments that match shared files', function () {
    [$thread, $task] = attachmentQuestionThread();
    $flow = Artifact::factory()->shared('flow.png')->create(['yak_task_id' => $task->id]);
    $inline = Artifact::factory()->shared('inline.html')->create(['yak_task_id' => $task->id]);
    $this->actingAs(User::factory()->create());

    $this->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('question.questions.0.question', 'How should 3DS work?')
        ->where('question.questions.0.context.0', [
            'id' => $flow->public_id,
            'name' => 'flow.png',
            'alt' => 'Flow today',
            'kind' => 'image',
            'url' => route('shared-files.show', ['publicId' => $flow->public_id]),
            'rawUrl' => route('shared-files.raw', ['publicId' => $flow->public_id]),
        ])
        ->where('question.questions.0.options.0.attachment.id', $inline->public_id)
        ->where('question.questions.0.options.0.attachment.kind', 'report')
        ->where('question.questions.0.options.0.previewHtml', fn (string $html): bool => str_contains($html, 'Applies the plan.') && ! str_contains($html, 'inline.html'))
        ->where('question.questions.0.options.1.attachment', null)
        ->where('question.questions.0.options.1.previewHtml', null));
});

it('drops references that match no shared file', function () {
    [$thread] = attachmentQuestionThread("Which?\n\n![Missing](missing.png)");
    $this->actingAs(User::factory()->create());

    $this->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('question.questions.0.question', 'Which?')
        ->where('question.questions.0.context', [])
        ->where('question.questions.0.options.0.attachment', null));
});

it('resolves the newest shared file of that name', function () {
    [$thread, $task] = attachmentQuestionThread();
    Artifact::factory()->shared('flow.png')->create(['yak_task_id' => $task->id]);
    $newest = Artifact::factory()->shared('flow.png')->create(['yak_task_id' => $task->id]);
    $this->actingAs(User::factory()->create());

    $this->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('question.questions.0.context.0.id', $newest->public_id));
});

it('keeps the raw question text for answers', function () {
    [, $task] = attachmentQuestionThread();

    expect(array_keys(ThreadLiveQuestions::answerTexts($task->clarificationRounds()[0], [])))
        ->toBe(["How should 3DS work?\n\n![Flow today](flow.png)"]);
});

it('shows answered rounds without attachment references', function () {
    [, $task] = attachmentQuestionThread();

    expect(ThreadBuilder::answerItems($task->clarificationRounds()[0])[0]['question'])->toBe('How should 3DS work?');
});
