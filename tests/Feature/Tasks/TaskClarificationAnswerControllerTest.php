<?php

use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->actingAs($this->user = User::factory()->create(['name' => 'Michele']));
    $this->task = YakTask::factory()->withClarificationQuestions()->create(['source' => 'linear']);
});

it('submits answers and redirects to the task', function () {
    $this->post(route('tasks.clarification-answers.store', $this->task), [
        'answers' => ['scope' => ['choices' => ['Small'], 'other' => null], 'data' => ['choices' => [], 'other' => 'Use staging']],
        'note' => 'Thanks',
    ])->assertRedirect(route('tasks.show', $this->task))->assertSessionHas('success', 'Sent to Yak.');

    expect($this->task->fresh()->clarificationAnswersAwaitingResume()['answered_by'])->toBe('Michele');
    Queue::assertPushed(RunYakJob::class);
});

it('accepts a skipped question', function () {
    $this->post(route('tasks.clarification-answers.store', $this->task), [
        'answers' => ['scope' => ['choices' => ['Small'], 'other' => null]],
    ])->assertSessionHasNoErrors();
});

it('rejects invalid answers', function (array $payload, string $errorKey) {
    $this->post(route('tasks.clarification-answers.store', $this->task), $payload)->assertSessionHasErrors($errorKey);
    Queue::assertNotPushed(RunYakJob::class);
})->with([
    'nothing answered' => [['answers' => ['scope' => ['choices' => [], 'other' => '  ']]], 'answers'],
    'unknown question' => [['answers' => ['nope' => ['choices' => ['Small'], 'other' => null]]], 'answers'],
    'unknown label' => [['answers' => ['scope' => ['choices' => ['Huge'], 'other' => null]]], 'answers.scope'],
    'two on single select' => [['answers' => ['scope' => ['choices' => ['Small', 'Large'], 'other' => null]]], 'answers.scope'],
    'long other' => [['answers' => ['scope' => ['choices' => [], 'other' => str_repeat('a', 2001)]]], 'answers.scope.other'],
    'long note' => [['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]], 'note' => str_repeat('a', 2001)], 'note'],
]);

it('tells a late submitter the questions were already answered', function () {
    $payload = ['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]]];
    $this->post(route('tasks.clarification-answers.store', $this->task), $payload);

    $this->post(route('tasks.clarification-answers.store', $this->task), $payload)
        ->assertSessionHas('error', 'These questions were already answered.');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('answers on the conversation head when posted to the root task', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success]);
    $this->task->update(['parent_task_id' => $root->id]);

    $this->post(route('tasks.clarification-answers.store', $root), ['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]]])
        ->assertRedirect(route('tasks.show', $root));

    expect($this->task->fresh()->clarificationAnswersAwaitingResume())->not->toBeNull();
});
