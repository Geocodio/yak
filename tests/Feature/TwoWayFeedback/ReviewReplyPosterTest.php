<?php

use App\Channels\GitHub\AppService;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\ReviewReplyPoster;

beforeEach(fn () => config()->set('yak.channels.github.installation_id', 77));

function repliedTask(array $overrides = []): YakTask
{
    return YakTask::factory()->awaitingCi()->create(array_merge([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'description' => "@alice submitted a review (commented):\n\nInline comments:\n\n- [c:101] app/Foo.php:7 — why a queue here?\n- [c:102] app/Bar.php:3 — rename this",
        'review_replies' => [101 => 'Because the upload is slow.', 102 => 'Renamed in a1b2c3d.'],
    ], $overrides));
}

it('replies on each comment thread', function () {
    $github = $this->mock(AppService::class);
    $github->shouldReceive('replyToReviewComment')->once()->with(77, 'acme/web', 9, 101, 'Because the upload is slow.')->andReturn(['id' => 1]);
    $github->shouldReceive('replyToReviewComment')->once()->with(77, 'acme/web', 9, 102, 'Renamed in a1b2c3d.')->andReturn(['id' => 2]);
    $github->shouldNotReceive('commentOnPullRequest');

    $task = repliedTask();
    app(ReviewReplyPoster::class)->post($task, 'acme/web', 9);

    expect(TaskLog::where('yak_task_id', $task->id)->where('message', 'Replied on review comment')->count())->toBe(2);
});

it('falls back to a general comment that quotes the original when the thread reply fails', function () {
    $github = $this->mock(AppService::class);
    $github->shouldReceive('replyToReviewComment')->once()->with(77, 'acme/web', 9, 101, 'Because the upload is slow.')->andThrow(new RuntimeException('GitHub rejected review-comment reply (status 404): gone'));
    $github->shouldReceive('replyToReviewComment')->once()->with(77, 'acme/web', 9, 102, 'Renamed in a1b2c3d.')->andReturn(['id' => 2]);
    $github->shouldReceive('commentOnPullRequest')->once()->withArgs(function (int $installationId, string $repo, int $number, string $body): bool {
        return $installationId === 77
            && $number === 9
            && str_contains($body, '> app/Foo.php:7 — why a queue here?')
            && str_contains($body, 'Because the upload is slow.');
    })->andReturn(true);

    $task = repliedTask();
    app(ReviewReplyPoster::class)->post($task, 'acme/web', 9);

    expect(TaskLog::where('yak_task_id', $task->id)->where('level', 'warning')->where('message', 'Thread reply failed; posted as a PR comment')->count())->toBe(1);
});

it('quotes only the comment id when the original line is not in the task description', function () {
    $github = $this->mock(AppService::class);
    $github->shouldReceive('replyToReviewComment')->once()->andThrow(new RuntimeException('nope'));
    $github->shouldReceive('commentOnPullRequest')->once()->withArgs(fn (int $i, string $r, int $n, string $body): bool => str_contains($body, 'review comment 555') && str_contains($body, 'Answer.'))->andReturn(true);

    $task = repliedTask(['description' => 'no tagged lines here', 'review_replies' => [555 => 'Answer.']]);
    app(ReviewReplyPoster::class)->post($task, 'acme/web', 9);
});

it('does nothing when the task has no replies', function () {
    $github = $this->mock(AppService::class);
    $github->shouldNotReceive('replyToReviewComment');
    $github->shouldNotReceive('commentOnPullRequest');

    app(ReviewReplyPoster::class)->post(repliedTask(['review_replies' => null]), 'acme/web', 9);
});

it('treats a rejected fallback comment as a failed reply', function () {
    $github = $this->mock(AppService::class);
    $github->shouldReceive('replyToReviewComment')->once()->andThrow(new RuntimeException('nope'));
    $github->shouldReceive('commentOnPullRequest')->once()->andReturn(false);
    $github->shouldReceive('replyToReviewComment')->once()->with(77, 'acme/web', 9, 102, 'Renamed in a1b2c3d.')->andReturn(['id' => 2]);

    $task = repliedTask();
    app(ReviewReplyPoster::class)->post($task, 'acme/web', 9);

    expect(TaskLog::where('yak_task_id', $task->id)->where('level', 'warning')->where('message', 'Review reply could not be posted')->count())->toBe(1);
    expect(TaskLog::where('yak_task_id', $task->id)->where('message', 'Thread reply failed; posted as a PR comment')->count())->toBe(0);
});

it('logs and skips replies when no GitHub installation id is configured', function () {
    config()->set('yak.channels.github.installation_id', 0);
    $github = $this->mock(AppService::class);
    $github->shouldNotReceive('replyToReviewComment');
    $github->shouldNotReceive('commentOnPullRequest');

    $task = repliedTask();
    app(ReviewReplyPoster::class)->post($task, 'acme/web', 9);

    expect(TaskLog::where('yak_task_id', $task->id)->where('level', 'warning')->where('message', 'Review replies skipped: no GitHub installation id')->count())->toBe(1);
});

it('continues when both the thread reply and the fallback fail', function () {
    $github = $this->mock(AppService::class);
    $github->shouldReceive('replyToReviewComment')->twice()->andThrow(new RuntimeException('nope'));
    $github->shouldReceive('commentOnPullRequest')->twice()->andThrow(new RuntimeException('also nope'));

    $task = repliedTask();
    app(ReviewReplyPoster::class)->post($task, 'acme/web', 9);

    expect(TaskLog::where('yak_task_id', $task->id)->where('level', 'warning')->count())->toBe(2);
});
