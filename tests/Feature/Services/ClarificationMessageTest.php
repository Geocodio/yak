<?php

use App\Models\YakTask;
use App\Services\ClarificationMessage;

it('asks a single question inline with numbered options in Slack and Linear', function (string $source) {
    $task = YakTask::factory()->create(['source' => $source]);
    $task->recordClarificationRound([sampleQuestion('scope', ['Small', 'Large'])], 'Summary');

    $message = ClarificationMessage::asked($task->fresh());

    expect(ClarificationMessage::answersInline($task->fresh()))->toBeTrue()
        ->and($message)->toContain('Which scope should I use?')
        ->and($message)->toContain("1. Small\n2. Large")
        ->and($message)->toContain('or write your own answer');
})->with(['slack', 'linear']);

it('links to the form for several questions', function () {
    $task = YakTask::factory()->create(['source' => 'linear']);
    $task->recordClarificationRound([sampleQuestion('scope'), sampleQuestion('data')], 'Summary');

    expect(ClarificationMessage::answersInline($task->fresh()))->toBeFalse()
        ->and(ClarificationMessage::asked($task->fresh()))
        ->toBe('I have 2 questions before I can continue: Scope, Data. Answer them here: ' . route('tasks.show', $task) . '#questions');
});

it('always links to the form from GitHub and the dashboard', function (string $source) {
    $task = YakTask::factory()->create(['source' => $source]);
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');

    expect(ClarificationMessage::asked($task->fresh()))
        ->toBe('I have a question before I can continue: Scope. Answer it here: ' . route('tasks.show', $task) . '#questions');
})->with(['github', 'dashboard']);

it('links follow-ups to the root task page', function () {
    $root = YakTask::factory()->create(['source' => 'slack']);
    $child = YakTask::factory()->create(['source' => 'github', 'parent_task_id' => $root->id]);

    expect(ClarificationMessage::formLink($child))->toBe(route('tasks.show', $root) . '#questions');
});

it('has no em dashes in any message', function () {
    $task = YakTask::factory()->create(['source' => 'slack', 'clarification_expires_at' => now()->addDays(4)]);
    $task->recordClarificationRound([sampleQuestion('scope'), sampleQuestion('data')], 'Summary');
    $task->refresh();

    foreach ([ClarificationMessage::asked($task), ClarificationMessage::pointToForm($task), ClarificationMessage::reminder($task)] as $message) {
        expect($message)->not->toContain('—');
    }
});
