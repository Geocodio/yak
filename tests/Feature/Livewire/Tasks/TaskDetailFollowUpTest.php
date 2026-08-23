<?php

use App\Jobs\RunFollowUpJob;
use App\Livewire\Tasks\TaskDetail;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('sendMessage on an open-PR task creates a chained follow-up and dispatches RunFollowUpJob', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'repo' => 'web',
        'branch_name' => 'yak/CSV-1',
        'session_id' => 'sess_parent',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
    ]);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->assertSet('composerState', 'follow_up')
        ->set('composerText', 'Also handle the empty-state')
        ->call('sendMessage')
        ->assertSet('composerText', '');

    expect(YakTask::where('parent_task_id', $task->id)->count())->toBe(1);
    Queue::assertPushed(RunFollowUpJob::class);
});

test('sendMessage on a merged PR creates nothing and dispatches nothing', function () {
    Queue::fake();

    $task = YakTask::factory()->merged()->create(['source' => 'dashboard', 'branch_name' => 'yak/M-1']);

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->set('composerText', 'too late')
        ->call('sendMessage');

    expect(YakTask::where('parent_task_id', $task->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('composer is shown for an open PR and hidden when no open PR', function () {
    $open = YakTask::factory()->success()->create(['source' => 'dashboard', 'branch_name' => 'yak/O-1']);
    $merged = YakTask::factory()->merged()->create(['source' => 'dashboard', 'branch_name' => 'yak/X-1']);

    Livewire::test(TaskDetail::class, ['task' => $open])
        ->assertSeeHtml('data-testid="follow-up-input"');

    Livewire::test(TaskDetail::class, ['task' => $merged])
        ->assertDontSeeHtml('data-testid="follow-up-input"');
});
