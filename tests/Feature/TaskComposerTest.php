<?php

use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Livewire\Tasks\TaskDetail;
use App\Models\PendingSteeringMessage;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(User::factory()->create()));

test('awaiting clarification routes to ClarificationReplyJob', function () {
    Queue::fake([ClarificationReplyJob::class]);
    $task = YakTask::factory()->create(['status' => TaskStatus::AwaitingClarification]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSet('composerState', 'clarification')
        ->set('composerText', 'Convert in place')
        ->call('sendMessage');

    Queue::assertPushed(ClarificationReplyJob::class);
});

test('running queues a steering message', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSet('composerState', 'steering')
        ->set('composerText', 'also handle IPv6')
        ->call('sendMessage');

    expect(PendingSteeringMessage::where('root_task_id', $task->id)->count())->toBe(1);
});

test('failed and closed states disable the composer', function () {
    $failed = YakTask::factory()->create(['status' => TaskStatus::Failed]);
    Livewire::test(TaskDetail::class, ['task' => $failed])->assertSet('composerState', 'disabled_failed');

    $done = YakTask::factory()->create(['status' => TaskStatus::Success, 'pr_url' => null]);
    Livewire::test(TaskDetail::class, ['task' => $done])->assertSet('composerState', 'disabled_closed');
});

test('clarification chips prefill the composer', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingClarification,
        'clarification_options' => ['Convert in place', 'Keep both'],
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('prefillOption', 'Convert in place')
        ->assertSet('composerText', 'Convert in place');
});
