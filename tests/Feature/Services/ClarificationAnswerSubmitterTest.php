<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\ClarificationAnswerSubmitter;
use Illuminate\Support\Facades\Queue;

it('stores answers, notifies the channel, and re-dispatches the job that asked', function () {
    Queue::fake();
    $task = YakTask::factory()->withClarificationQuestions()->create(['source' => 'linear', 'mode' => TaskMode::Fix]);

    $submitted = app(ClarificationAnswerSubmitter::class)->submit($task, ['scope' => ['choices' => ['Small'], 'other' => null]], 'Go', 'Michele', 'dashboard');

    $task->refresh();
    expect($submitted)->toBeTrue()
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->clarification_expires_at)->toBeNull()
        ->and($task->clarificationAnswersAwaitingResume()['answers']['scope']['choices'])->toBe(['Small']);
    Queue::assertPushed(RunYakJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Progress && $job->message === 'Got your answers, continuing.');
});

it('dispatches RunFollowUpJob for a follow-up and ResearchYakJob for research', function (array $attributes, string $job) {
    Queue::fake();
    $parent = YakTask::factory()->create();
    $task = YakTask::factory()->withClarificationQuestions()->create(array_merge($attributes, ($attributes['parent_task_id'] ?? false) ? ['parent_task_id' => $parent->id] : []));

    app(ClarificationAnswerSubmitter::class)->submit($task, ['scope' => ['choices' => ['Small'], 'other' => null]], null, 'Michele', 'dashboard');

    Queue::assertPushed($job);
})->with([
    'follow-up' => [['mode' => TaskMode::Fix, 'parent_task_id' => true], RunFollowUpJob::class],
    'research' => [['mode' => TaskMode::Research], ResearchYakJob::class],
]);

it('ignores a second submit for the same round', function () {
    Queue::fake();
    $task = YakTask::factory()->withClarificationQuestions()->create();
    $submitter = app(ClarificationAnswerSubmitter::class);

    $submitter->submit($task, ['scope' => ['choices' => ['Small'], 'other' => null]], null, 'A', 'dashboard');
    $second = $submitter->submit($task->fresh(), ['scope' => ['choices' => ['Large'], 'other' => null]], null, 'B', 'slack');

    expect($second)->toBeFalse()
        ->and($task->fresh()->clarificationAnswersAwaitingResume()['answered_by'])->toBe('A');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('matches a reply by number, by label, or treats it as other text', function (string $reply, array $expected) {
    $question = sampleQuestion('scope', ['Small', 'Large']);

    expect(ClarificationAnswerSubmitter::matchReply($question, $reply))->toBe($expected);
})->with([
    'number' => ['2', ['choices' => ['Large'], 'other' => null]],
    'label any case' => [' small ', ['choices' => ['Small'], 'other' => null]],
    'free text' => ['Medium please', ['choices' => [], 'other' => 'Medium please']],
    'out of range' => ['7', ['choices' => [], 'other' => '7']],
]);
