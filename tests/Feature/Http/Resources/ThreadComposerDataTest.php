<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Http\Resources\ThreadComposerData;
use App\Models\ClaudeAccount;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

it('is idle on an open thread without a running turn', function () {
    $account = ClaudeAccount::factory()->create(['name' => 'Work']);
    $thread = Thread::factory()->create(['model' => 'opus', 'claude_account_id' => $account->id]);

    expect(ThreadComposerData::for($thread, User::factory()->create()))->toBe([
        'state' => 'idle',
        'model' => 'opus',
        'models' => ['opus', 'sonnet', 'haiku'],
        'accountName' => 'Work',
        'nextAttachmentNumber' => 1,
    ]);
});

it('is working while a turn runs', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    expect(ThreadComposerData::for($thread, User::factory()->create())['state'])->toBe('working');
});

it('maps thread conditions to composer states', function (array $attributes, string $expected) {
    $thread = Thread::factory()->create($attributes);

    expect(ThreadComposerData::for($thread, User::factory()->create())['state'])->toBe($expected);
})->with([
    'closed' => [['status' => 'closed'], 'disabled_closed'],
    'computer owns it' => [['owner_side' => ThreadOwnerSide::Device], 'disabled_computer'],
    'invalid account' => [['blocked_reason' => ThreadBlockReason::AccountInvalid], 'disabled_blocked'],
    'restore failed disables the composer so Retry stays the only way back' => [['blocked_reason' => ThreadBlockReason::RestoreFailed], 'disabled_blocked'],
    'usage limit keeps typing open' => [['blocked_reason' => ThreadBlockReason::UsageLimit], 'idle'],
    'waiting for a sandbox keeps typing open' => [['blocked_reason' => ThreadBlockReason::WaitingForSandbox], 'idle'],
]);

it('is readonly for someone who cannot drive the thread', function () {
    $thread = Thread::factory()->private()->create();

    expect(ThreadComposerData::for($thread, User::factory()->create())['state'])->toBe('readonly')
        ->and(ThreadComposerData::for($thread, null)['state'])->toBe('readonly');
});

it('numbers the next attachment after the existing ones', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskAttachment::factory()->count(2)->create(['yak_task_id' => $task->id]);

    expect(ThreadComposerData::for($thread, User::factory()->create())['nextAttachmentNumber'])->toBe(3);
});
