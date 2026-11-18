<?php

use App\Jobs\RunYakJob;
use App\Models\User;
use App\Models\YakTask;
use App\Support\TaskPageUrl;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->actingAs($this->user = User::factory()->create(['name' => 'Michele']));
    $this->task = YakTask::factory()->withClarificationQuestions()->create(['source' => 'linear']);
});

it('submits answers and redirects to the task', function () {
    $this->post(route('threads.clarification-answers.store', $this->task->thread), [
        'answers' => ['scope' => ['choices' => ['Small'], 'other' => null], 'data' => ['choices' => [], 'other' => 'Use staging']],
        'note' => 'Thanks',
    ])->assertRedirect(TaskPageUrl::forOrLog($this->task))->assertSessionHas('success', 'Sent to Yak.');

    expect($this->task->fresh()->clarificationAnswersAwaitingResume()['answered_by'])->toBe('Michele');
    Queue::assertPushed(RunYakJob::class);
});

it('accepts a skipped question', function () {
    $this->post(route('threads.clarification-answers.store', $this->task->thread), [
        'answers' => ['scope' => ['choices' => ['Small'], 'other' => null]],
    ])->assertSessionHasNoErrors();
});

it('rejects invalid answers', function (array $payload, string $errorKey) {
    $this->post(route('threads.clarification-answers.store', $this->task->thread), $payload)->assertSessionHasErrors($errorKey);
    Queue::assertNotPushed(RunYakJob::class);
})->with([
    'nothing answered' => [['answers' => ['scope' => ['choices' => [], 'other' => '  ']]], 'answers'],
    'unknown question' => [['answers' => ['nope' => ['choices' => ['Small'], 'other' => null]]], 'answers'],
    'unknown label' => [['answers' => ['scope' => ['choices' => ['Huge'], 'other' => null]]], 'answers.scope'],
    'two on single select' => [['answers' => ['scope' => ['choices' => ['Small', 'Large'], 'other' => null]]], 'answers.scope'],
    'nested choice' => [['answers' => ['scope' => ['choices' => [['x']], 'other' => null]]], 'answers.scope.choices.0'],
    'long other' => [['answers' => ['scope' => ['choices' => [], 'other' => str_repeat('a', 2001)]]], 'answers.scope.other'],
    'long note' => [['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]], 'note' => str_repeat('a', 2001)], 'note'],
]);

it('tells a late submitter the questions were already answered', function () {
    $payload = ['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]]];
    $this->post(route('threads.clarification-answers.store', $this->task->thread), $payload);

    $this->post(route('threads.clarification-answers.store', $this->task->thread), $payload)
        ->assertSessionHas('error', 'These questions were already answered or have expired. Reload the thread to see where it stands.');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('flashes a busy message when the lock cannot be taken in time', function () {
    YakTask::updating(fn () => throw tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']));

    $this->post(route('threads.clarification-answers.store', $this->task->thread), ['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]]])
        ->assertRedirect(TaskPageUrl::forOrLog($this->task))
        ->assertSessionHas('error', 'Yak is busy with this conversation. Try again in a few seconds.');
    Queue::assertNotPushed(RunYakJob::class);
});
