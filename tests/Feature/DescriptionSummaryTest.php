<?php

use App\Jobs\SummarizeTaskDescriptionJob;
use App\Models\YakTask;
use App\Services\TaskDescriptionSummary;
use Illuminate\Support\Facades\Queue;

test('long descriptions dispatch the summarize job on create', function () {
    Queue::fake([SummarizeTaskDescriptionJob::class]);

    YakTask::factory()->create(['description' => str_repeat('word ', 300)]);

    Queue::assertPushed(SummarizeTaskDescriptionJob::class);
});

test('short descriptions do not dispatch', function () {
    Queue::fake([SummarizeTaskDescriptionJob::class]);

    YakTask::factory()->create(['description' => 'Fix the login bug']);

    Queue::assertNotPushed(SummarizeTaskDescriptionJob::class);
});

test('job stores the summary', function () {
    $task = YakTask::factory()->create(['description' => str_repeat('word ', 300)]);

    $this->mock(TaskDescriptionSummary::class)
        ->shouldReceive('generate')->once()->andReturn('A short summary');

    (new SummarizeTaskDescriptionJob($task))->handle(app(TaskDescriptionSummary::class));

    expect($task->fresh()->description_summary)->toBe('A short summary');
});

test('job leaves summary null when generation fails', function () {
    $task = YakTask::factory()->create(['description' => str_repeat('word ', 300)]);

    $this->mock(TaskDescriptionSummary::class)
        ->shouldReceive('generate')->once()->andReturn(null);

    (new SummarizeTaskDescriptionJob($task))->handle(app(TaskDescriptionSummary::class));

    expect($task->fresh()->description_summary)->toBeNull();
});
