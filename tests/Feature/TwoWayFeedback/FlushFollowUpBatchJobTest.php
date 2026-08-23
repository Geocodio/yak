<?php

use App\Channels\GitHub\AppService;
use App\Jobs\FlushFollowUpBatchJob;
use App\Jobs\RunFollowUpJob;
use App\Models\FollowUpPendingComment;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => config()->set('yak.channels.github.installation_id', 4242));

test('flushes buffered comments into one follow-up and clears the buffer', function () {
    Queue::fake();

    $root = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'branch_name' => 'yak/CSV-1',
        'session_id' => 'sess',
    ]);

    foreach (['handle empty state', 'rename the column', 'add a test'] as $i => $body) {
        FollowUpPendingComment::create([
            'yak_task_id' => $root->id,
            'pr_url' => $root->pr_url,
            'body' => $body,
            'file' => $i === 1 ? 'app/Report.php' : null,
            'line' => $i === 1 ? 42 : null,
            'diff_hunk' => $i === 1 ? '@@ -1 +1 @@' : null,
        ]);
    }

    (new FlushFollowUpBatchJob($root->pr_url))->handle(
        app(FollowUpTaskFactory::class),
        app(AppService::class),
    );

    // one chained follow-up created, carrying all three instructions
    $child = YakTask::where('parent_task_id', $root->id)->first();
    expect($child)->not->toBeNull()
        ->and($child->description)->toContain('handle empty state')
        ->and($child->description)->toContain('rename the column')
        ->and($child->description)->toContain('add a test')
        ->and($child->description)->toContain('app/Report.php');

    // buffer cleared
    expect(FollowUpPendingComment::where('pr_url', $root->pr_url)->count())->toBe(0);
    Queue::assertPushed(RunFollowUpJob::class);
});

test('empty buffer is a no-op', function () {
    Queue::fake();

    (new FlushFollowUpBatchJob('https://github.com/acme/web/pull/404'))->handle(
        app(FollowUpTaskFactory::class),
        app(AppService::class),
    );

    expect(YakTask::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('merged PR posts a decline comment and creates no follow-up', function () {
    Queue::fake();
    $github = $this->mock(AppService::class);
    $github->shouldReceive('commentOnPullRequest')->once()
        ->withArgs(fn ($inst, $slug, $num, $body) => $num === 9);

    $root = YakTask::factory()->merged()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'branch_name' => 'yak/CSV-1',
    ]);
    FollowUpPendingComment::create(['yak_task_id' => $root->id, 'pr_url' => $root->pr_url, 'body' => 'too late']);

    (new FlushFollowUpBatchJob($root->pr_url))->handle(
        app(FollowUpTaskFactory::class),
        $github,
    );

    expect(YakTask::where('parent_task_id', $root->id)->count())->toBe(0)
        ->and(FollowUpPendingComment::where('pr_url', $root->pr_url)->count())->toBe(0); // buffer still cleared
    Queue::assertNothingPushed();
});

test('follow-up task takes its author from the buffered comments', function () {
    Queue::fake();

    $root = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/14',
        'pr_number' => 14,
        'branch_name' => 'yak/AU-2',
    ]);

    FollowUpPendingComment::create([
        'yak_task_id' => $root->id,
        'pr_url' => $root->pr_url,
        'body' => 'tweak this',
        'author' => 'mathias',
    ]);

    (new FlushFollowUpBatchJob($root->pr_url))->handle(
        app(FollowUpTaskFactory::class),
        app(AppService::class),
    );

    expect(YakTask::where('parent_task_id', $root->id)->first()->author_name)->toBe('mathias');
});
