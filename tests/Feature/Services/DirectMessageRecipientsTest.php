<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Models\User;
use App\Models\YakTask;
use App\Services\DirectMessageRecipients;

/**
 * Names of the users who get a DM for a task with a distinct starter
 * ("Starter") and responsible user ("Owner").
 *
 * @param  array<string, mixed>  $taskAttributes
 * @return list<string>
 */
function directMessageRecipientNames(string $source, NotificationType $type, array $taskAttributes = []): array
{
    $starter = User::factory()->create(['name' => 'Starter']);
    $owner = User::factory()->create(['name' => 'Owner']);

    $task = YakTask::factory()->create([
        'source' => $source,
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $owner->id,
        'pr_url' => null,
        ...$taskAttributes,
    ]);

    return array_map(fn (User $user): string => $user->name, app(DirectMessageRecipients::class)->for($task, $type));
}

dataset('routing table', [
    'slack result' => ['slack', NotificationType::Result, ['pr_url' => 'https://github.com/o/r/pull/1'], []],
    'slack clarification' => ['slack', NotificationType::Clarification, [], []],
    'github error' => ['github', NotificationType::Error, [], []],
    'cli pr ready' => ['cli', NotificationType::Result, ['pr_url' => 'https://github.com/o/r/pull/1'], []],
    'system error' => ['system', NotificationType::Error, [], []],
    'linear clarification' => ['linear', NotificationType::Clarification, [], ['Starter']],
    'linear reminder' => ['linear', NotificationType::Reminder, [], ['Starter']],
    'linear answer ready' => ['linear', NotificationType::Result, [], ['Starter']],
    'linear pr ready' => ['linear', NotificationType::Result, ['pr_url' => 'https://github.com/o/r/pull/1'], ['Starter', 'Owner']],
    'linear error' => ['linear', NotificationType::Error, [], ['Starter']],
    'linear cancelled' => ['linear', NotificationType::Cancelled, [], ['Starter']],
    'dashboard clarification' => ['dashboard', NotificationType::Clarification, [], ['Starter']],
    'dashboard pr ready' => ['dashboard', NotificationType::Result, ['pr_url' => 'https://github.com/o/r/pull/1'], ['Starter', 'Owner']],
    'dashboard error' => ['dashboard', NotificationType::Error, [], ['Starter']],
    'dashboard setup finished' => ['dashboard', NotificationType::Result, ['mode' => TaskMode::Setup], ['Starter']],
    'sentry pr ready' => ['sentry', NotificationType::Result, ['started_by_user_id' => null, 'pr_url' => 'https://github.com/o/r/pull/1'], ['Owner']],
    'sentry error' => ['sentry', NotificationType::Error, ['started_by_user_id' => null], []],
    'flaky-test pr ready' => ['flaky-test', NotificationType::Result, ['started_by_user_id' => null, 'pr_url' => 'https://github.com/o/r/pull/1'], ['Owner']],
    'flaky-test error' => ['flaky-test', NotificationType::Error, ['started_by_user_id' => null], []],
    'acknowledgment' => ['dashboard', NotificationType::Acknowledgment, [], []],
    'progress' => ['linear', NotificationType::Progress, [], []],
    'retry' => ['dashboard', NotificationType::Retry, [], []],
    'expiry' => ['linear', NotificationType::Expiry, [], []],
]);

test('routes direct messages by source and event', function (string $source, NotificationType $type, array $taskAttributes, array $expectedNames) {
    expect(directMessageRecipientNames($source, $type, $taskAttributes))->toBe($expectedNames);
})->with('routing table');

test('a starter who is also responsible gets one message', function () {
    $person = User::factory()->create();
    $task = YakTask::factory()->create([
        'source' => 'dashboard',
        'started_by_user_id' => $person->id,
        'responsible_user_id' => $person->id,
        'pr_url' => 'https://github.com/o/r/pull/1',
    ]);

    expect(app(DirectMessageRecipients::class)->for($task, NotificationType::Result))->toHaveCount(1);
});

test('a user with direct messages turned off gets none', function () {
    $starter = User::factory()->create(['direct_messages_enabled' => false]);
    $owner = User::factory()->create();
    $task = YakTask::factory()->create([
        'source' => 'linear',
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $owner->id,
        'pr_url' => 'https://github.com/o/r/pull/1',
    ]);

    $recipients = app(DirectMessageRecipients::class)->for($task, NotificationType::Result);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]->is($owner))->toBeTrue();
});

test('a follow-up result goes to the starter and not again to the responsible user', function () {
    $starter = User::factory()->create();
    $owner = User::factory()->create();
    $parent = YakTask::factory()->success()->create(['source' => 'dashboard', 'started_by_user_id' => $starter->id]);
    $followUp = YakTask::factory()->create([
        'source' => 'dashboard',
        'parent_task_id' => $parent->id,
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $owner->id,
        'pr_url' => 'https://github.com/o/r/pull/1',
    ]);

    $recipients = app(DirectMessageRecipients::class)->for($followUp, NotificationType::Result);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]->is($starter))->toBeTrue()
        ->and(app(DirectMessageRecipients::class)->for($followUp, NotificationType::Error))->toHaveCount(1);
});

test('a task with no linked users gets no message', function () {
    $task = YakTask::factory()->create(['source' => 'dashboard', 'pr_url' => 'https://github.com/o/r/pull/1']);

    expect(app(DirectMessageRecipients::class)->for($task, NotificationType::Result))->toBe([]);
});
