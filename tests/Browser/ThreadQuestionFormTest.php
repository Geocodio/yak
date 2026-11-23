<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\TaskStatus;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventProjector;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\Queue;

it('answers a question from the form in the conversation', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $user->id]);
    $task->recordClarificationRound([ClarificationQuestion::fromArray([
        'id' => 'on_failure',
        'header' => 'On failure',
        'question' => 'What should happen to the row?',
        'options' => [['label' => 'Mark failed', 'description' => 'Keep going.'], ['label' => 'Fail the batch', 'description' => 'Stop.']],
        'multi_select' => false,
    ])], 'I need one decision.');
    $json = '{"questions":[{"id":"on_failure","header":"On failure","question":"What should happen to the row?","options":[{"label":"Mark failed","description":"Keep going."},{"label":"Fail the batch","description":"Stop."}],"multi_select":false}]}';
    $entry = (new ThreadEventProjector)->withHtml(['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [['type' => 'text', 'text' => "I need one decision.\n\n```clarification\n{$json}\n```"]]]);
    app(ThreadEventPublisher::class)->append($thread->id, 'entry', $entry);

    visit("/threads/{$thread->public_id}")
        ->assertSee('I need one decision.')
        ->assertDontSee('multi_select')
        ->assertSeeIn('[data-testid="questions-form"]', 'Mark failed')
        ->assertSeeIn('[data-testid="questions-form"]', 'Answers go to Claude as the next message.')
        ->click('[data-testid="questions-form"] [role="radio"] >> nth=0')
        ->click('[data-testid="questions-submit"]')
        ->assertMissing('[data-testid="questions-form"]');

    expect($task->fresh()->clarificationAnswersAwaitingResume()['answers']['on_failure']['choices'])->toBe(['Mark failed']);
});

it('answers some questions, skips one, uses other and the note, and sends', function () {
    Queue::fake();
    $user = User::factory()->create(['name' => 'Michele']);
    $this->actingAs($user);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    $task = YakTask::factory()->withClarificationQuestions([
        ['id' => 'scope', 'header' => 'Scope', 'question' => 'Which scope?', 'options' => [['label' => 'Small', 'description' => 'Just pay as you go'], ['label' => 'Large', 'description' => 'Everyone']], 'multi_select' => false],
        ['id' => 'data', 'header' => 'Data', 'question' => 'Which data?', 'options' => [['label' => 'Live', 'description' => ''], ['label' => 'Sample', 'description' => '']], 'multi_select' => false],
        ['id' => 'demo', 'header' => 'Demo', 'question' => 'Demo accounts?', 'options' => [['label' => 'Leave out', 'description' => ''], ['label' => 'Include', 'description' => '']], 'multi_select' => false],
    ])->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="questions-form"]')
        ->assertDisabled('[data-testid="questions-submit"]')
        ->click('[data-testid="question-scope"] >> text=Small')
        ->assertEnabled('[data-testid="questions-submit"]')
        ->click('[data-testid="questions-next"]')
        ->click('[data-testid="question-data"] >> text=Other')
        ->fill('[data-testid="question-data-other"]', 'Use staging')
        ->fill('[data-testid="questions-note"]', 'Thanks')
        ->assertSee('2 of 3 answered')
        ->click('[data-testid="questions-submit"]')
        ->assertMissing('[data-testid="questions-form"]');

    $round = $task->fresh()->clarificationAnswersAwaitingResume();
    expect($round['answers'])->toEqual([
        'scope' => ['choices' => ['Small'], 'other' => null],
        'data' => ['choices' => [], 'other' => 'Use staging'],
    ])->and($round['note'])->toBe('Thanks');
});

it('shows the question form at phone width without sideways scroll', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    YakTask::factory()->withClarificationQuestions()->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);

    $page = visit("/threads/{$thread->public_id}")->on()->mobile()
        ->assertVisible('[data-testid="questions-form"]')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.innerWidth'))->toBe(375);
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('keeps the composer and its Send button on screen at 375 x 667 with the question form open', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    YakTask::factory()->withClarificationQuestions()->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);

    $page = visit("/threads/{$thread->public_id}");
    $page->resize(375, 667)->assertVisible('[data-testid="questions-form"]')->assertVisible('[data-testid="thread-send"]');

    $fits = $page->script(<<<'JS'
        (async () => {
            const measure = () => ['thread-composer', 'thread-composer-input', 'thread-send'].map((testId) => {
                const rect = document.querySelector('[data-testid="' + testId + '"]').getBoundingClientRect();
                return [testId, rect.top >= 0 && rect.bottom <= window.innerHeight + 1 && rect.left >= 0 && rect.right <= window.innerWidth + 1];
            });
            for (let attempt = 0; attempt < 60 && ! measure().every(([, fits]) => fits); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return measure();
        })()
    JS);

    expect($fits)->toBe([['thread-composer', true], ['thread-composer-input', true], ['thread-send', true]])
        ->and($page->script('document.documentElement.scrollHeight <= window.innerHeight + 1'))->toBeTrue();

    $page->click('[data-testid="questions-form"] [role="radio"] >> nth=0')->click('[data-testid="questions-submit"]')->assertMissing('[data-testid="questions-form"]');
});
