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
