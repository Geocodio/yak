<?php

use App\Enums\TaskMode;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Models\YakTask;

it('records a round and returns its pending questions', function () {
    $task = YakTask::factory()->create();

    $task->recordClarificationRound([sampleQuestion('scope'), sampleQuestion('data')], 'I found two problems.');

    expect($task->fresh()->clarificationRoundCount())->toBe(1)
        ->and(array_map(fn ($q) => $q->id, $task->fresh()->pendingClarificationQuestions()))->toBe(['scope', 'data'])
        ->and($task->fresh()->clarificationRounds()[0]['summary'])->toBe('I found two problems.');
});

it('stops returning pending questions once answered, and holds answers until consumed', function () {
    $task = YakTask::factory()->create();
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');

    $task->recordClarificationAnswers(['scope' => ['choices' => ['Option A'], 'other' => null]], 'Ship it', 'Michele');

    $task->refresh();
    expect($task->pendingClarificationQuestions())->toBe([])
        ->and($task->clarificationAnswersAwaitingResume()['answered_by'])->toBe('Michele')
        ->and($task->clarificationAnswersAwaitingResume()['note'])->toBe('Ship it');

    $task->markClarificationAnswersConsumed();

    expect($task->fresh()->clarificationAnswersAwaitingResume())->toBeNull();
});

it('picks the job that asked', function (array $attributes, string $expected) {
    $parent = YakTask::factory()->create();
    $task = YakTask::factory()->create(array_merge($attributes, isset($attributes['parent_task_id']) ? ['parent_task_id' => $parent->id] : []));

    expect($task->agentJobClass())->toBe($expected);
})->with([
    'fix' => [['mode' => TaskMode::Fix], RunYakJob::class],
    'fix follow-up' => [['mode' => TaskMode::Fix, 'parent_task_id' => true], RunFollowUpJob::class],
    'research' => [['mode' => TaskMode::Research], ResearchYakJob::class],
    'research follow-up' => [['mode' => TaskMode::Research, 'parent_task_id' => true], ResearchFollowUpJob::class],
]);
