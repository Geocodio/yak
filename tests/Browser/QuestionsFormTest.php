<?php

use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

it('answers some questions, skips one, uses other and the note, and sends', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['name' => 'Michele']));
    $task = YakTask::factory()->withClarificationQuestions([
        ['id' => 'scope', 'header' => 'Scope', 'question' => 'Which scope?', 'options' => [['label' => 'Small', 'description' => 'Just PAYG'], ['label' => 'Large', 'description' => 'Everyone']], 'multi_select' => false],
        ['id' => 'data', 'header' => 'Data', 'question' => 'Which data?', 'options' => [['label' => 'Live', 'description' => ''], ['label' => 'Sample', 'description' => '']], 'multi_select' => false],
        ['id' => 'demo', 'header' => 'Demo', 'question' => 'Demo accounts?', 'options' => [['label' => 'Leave out', 'description' => ''], ['label' => 'Include', 'description' => '']], 'multi_select' => false],
    ])->create();

    $page = visit(route('tasks.show', $task));

    $page->assertVisible('[data-testid="questions-form"]')
        ->assertMissing('[data-testid="composer"]')
        ->assertDisabled('[data-testid="questions-submit"]')
        ->click('[data-testid="question-scope"] >> text=Small')
        ->assertEnabled('[data-testid="questions-submit"]')
        ->click('[data-testid="question-data"] >> text=Other')
        ->fill('[data-testid="question-data-other"]', 'Use staging')
        ->fill('[data-testid="questions-note"]', 'Thanks')
        ->assertSee('2 of 3 answered')
        ->click('[data-testid="questions-submit"]')
        ->assertSee('Sent to Yak.');

    $round = $task->fresh()->clarificationAnswersAwaitingResume();
    expect($round['answers'])->toEqual([
        'scope' => ['choices' => ['Small'], 'other' => null],
        'data' => ['choices' => [], 'other' => 'Use staging'],
    ])->and($round['note'])->toBe('Thanks');
});

it('works at phone width', function () {
    $this->actingAs(User::factory()->create());
    $task = YakTask::factory()->withClarificationQuestions()->create();

    visit(route('tasks.show', $task))->resize(375, 812)
        ->assertVisible('[data-testid="questions-form"]')
        ->assertNoJavaScriptErrors();
});
