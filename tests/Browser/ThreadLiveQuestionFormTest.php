<?php

use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;

require_once __DIR__ . '/../Support/LiveQuestionHelpers.php';

beforeEach(function () {
    app()->singleton(BrokerStreams::class, FakeBrokerStreams::class);
});

it('shows Claude\'s question mid-turn and sends the answer to the runner', function () {
    [$thread, $run, $task, $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    visit("/threads/{$thread->public_id}")
        ->assertSeeIn('[data-testid="questions-live"]', 'Claude is waiting for your answer · 2 questions')
        ->assertSeeIn('[data-testid="questions-form"]', 'Which screens first?')
        ->assertSeeIn('[data-testid="questions-form"]', 'Let Claude decide')
        ->assertMissing('[data-testid="questions-note"]')
        ->assertDisabled('[data-testid="questions-submit"]')
        ->click('[data-testid="question-q1"] [role="checkbox"] >> nth=0')
        ->click('[data-testid="question-q1"] [role="checkbox"] >> nth=1')
        ->click('[data-testid="question-q2"] >> text=Other')
        ->fill('[data-testid="question-q2-other"]', 'Both, desktop first')
        ->assertSee('2 of 2 answered')
        ->click('[data-testid="questions-submit"]')
        ->assertMissing('[data-testid="questions-form"]');

    $command = collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->firstWhere('type', 'answer_question');

    expect((array) $command['answers'])->toBe(['Which screens first?' => 'Contacts, Deals', 'Which width?' => 'Both, desktop first'])
        ->and($task->fresh()->liveClarificationRound())->toBeNull();
});

it('lets Claude decide from the live question form', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [widthQuestion()]);

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="questions-decide"]')
        ->assertMissing('[data-testid="questions-form"]');

    expect(collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->firstWhere('type', 'decline_question')['message'])->toBe('The person asked you to decide.');
});

it('fits the live question form at phone width', function () {
    [$thread, , , $user] = threadWithRunningTurn();
    $this->actingAs($user);
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    $page = visit("/threads/{$thread->public_id}")->on()->mobile()
        ->assertVisible('[data-testid="questions-live"]')
        ->assertVisible('[data-testid="questions-decide"]')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
