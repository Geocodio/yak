<?php

use App\Enums\ThreadLockHolder;
use App\Http\Resources\ThreadMessagesData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

function startTurnAndCapturePrompt(YakTask $task, string $text, ?string $shownText = null): string
{
    $sent = null;
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'setModel');
    $fakes['commands']->shouldReceive('turn')->andReturnUsing(function ($thread, $uuid, $prompt) use (&$sent) {
        $sent = $prompt;
    });

    $run = app(ThreadTurns::class)->start($task, $text, User::factory()->create(), null, shownText: $shownText);

    // Frees the thread so the next start is not busy.
    Thread::query()->whereKey($run->thread_id)->update(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null]);
    TaskRun::query()->whereKey($run->id)->update(['turn_state' => 'completed']);

    return (string) $sent;
}

function threadStartedOnComputer(): array
{
    [$thread, $task] = threadWithTask();
    $task->forceFill(['source' => YakTask::SOURCE_TELEPORT])->save();
    $thread->forceFill(['append_system_prompt' => 'RULE FIVE: commits start with the task id.'])->save();

    return [$thread, $task];
}

it('puts the standing instructions in the first prompt of a teleported thread and keeps the shown text', function () {
    [$thread, $task] = threadStartedOnComputer();

    $prompt = startTurnAndCapturePrompt($task, 'continue with the cache tests', 'continue with the cache tests');

    expect($prompt)->toContain('<yak-standing-instructions>')
        ->and($prompt)->toContain('take precedence over earlier instructions')
        ->and($prompt)->toEndWith("</yak-standing-instructions>\n\ncontinue with the cache tests")
        ->and($thread->refresh()->standing_instructions_delivered_at)->not->toBeNull()
        ->and(ThreadMessagesData::for($thread)[0]['text'])->toBe('continue with the cache tests');
});

it('shows the person text when no shown text is given', function () {
    [$thread, $task] = threadStartedOnComputer();

    startTurnAndCapturePrompt($task, 'first message after the up');

    expect(ThreadMessagesData::for($thread)[0]['text'])->toBe('first message after the up');
});

it('carries the block once: the next turn and a later up send the plain message', function () {
    [$thread, $task] = threadStartedOnComputer();

    $first = startTurnAndCapturePrompt($task, 'first message');
    $second = startTurnAndCapturePrompt($task, 'second message');

    expect($first)->toContain('<yak-standing-instructions>')
        ->and($second)->toBe('second message');
});

it('does not repeat the block when the thread was delivered before a down and an up', function () {
    [$thread, $task] = threadStartedOnComputer();
    $thread->forceFill(['standing_instructions_delivered_at' => now()->subDay()])->save();

    expect(startTurnAndCapturePrompt($task, 'after the second up'))->toBe('after the second up');
});

it('leaves a thread started on Yak alone', function () {
    [$thread, $task] = threadWithTask();

    expect(startTurnAndCapturePrompt($task, 'build it'))->toBe('build it')
        ->and($thread->refresh()->standing_instructions_delivered_at)->toBeNull();
});
