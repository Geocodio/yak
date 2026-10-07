<?php

use App\Enums\TaskStatus;
use App\Models\YakTask;
use App\Services\ClarificationMessage;

it('asks a single question inline with numbered options in Slack and Linear', function (string $source) {
    $task = YakTask::factory()->create(['source' => $source]);
    $task->recordClarificationRound([sampleQuestion('scope', ['Small', 'Large'])], 'Summary');

    $message = ClarificationMessage::asked($task->fresh());

    expect(ClarificationMessage::answersInline($task->fresh()))->toBeTrue()
        ->and($message)->toContain('Which scope should I use?')
        ->and($message)->toContain("1. Small: Use Small.\n2. Large: Use Large.")
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

it('leaves out an empty option description in the inline question', function () {
    $task = YakTask::factory()->withClarificationQuestions([sampleQuestion('scope', ['Small', 'Large'])->toArray()])->create(['source' => 'slack']);
    $rounds = $task->clarificationRounds();
    $rounds[0]['questions'][0]['options'][1]['description'] = '';
    $task->update(['clarification_rounds' => $rounds]);

    expect(ClarificationMessage::asked($task->fresh()))->toContain("1. Small: Use Small.\n2. Large\n");
});

it('points to the form with wording that fits the question count', function (int $questionCount, string $expected) {
    $task = YakTask::factory()->create(['source' => 'slack', 'status' => TaskStatus::AwaitingClarification]);
    if ($questionCount > 0) {
        $task->recordClarificationRound(array_map(fn (int $index) => sampleQuestion("q{$index}"), range(1, $questionCount)), 'Summary');
    }

    expect(ClarificationMessage::pointToForm($task->fresh()))->toBe(str_replace('{link}', route('tasks.show', $task) . '#questions', $expected));
})->with([
    'none' => [0, 'Please answer on the task page: {link}'],
    'one' => [1, 'Please answer the question on the form: {link}'],
    'several' => [3, 'There are 3 questions, so please answer them together on the form: {link}'],
]);
