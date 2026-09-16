<?php

use App\Ai\Agents\ReviewFeedbackTriage;
use App\Channels\GitHub\AppService;
use App\Jobs\RunFollowUpJob;
use App\Jobs\TriageReviewJob;
use App\Models\GitHubInstallationToken;
use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 4242);
    config()->set('yak.followup.github_prefixes', '/yak,@yak-bot[bot],yak:');

    GitHubInstallationToken::create([
        'installation_id' => 4242,
        'token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
});

function yakReviewTask(array $overrides = []): YakTask
{
    return YakTask::factory()->success()->create(array_merge([
        'source' => 'github',
        'repo' => 'acme/web',
        'branch_name' => 'yak/CSV-1',
        'session_id' => 'sess',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'description' => 'Add CSV export',
    ], $overrides));
}

/**
 * @param  array<int, array<string, mixed>>  $comments
 */
function fakeReviewApi(array $comments): void
{
    Http::fake([
        'api.github.com/repos/acme/web/pulls/9/reviews/500/comments*' => Http::response($comments, 200),
        'api.github.com/repos/acme/web/pulls/comments/*/reactions' => Http::response(['id' => 777], 201),
        'api.github.com/repos/acme/web/pulls/comments/*/reactions/*' => Http::response('', 204),
        'api.github.com/*' => Http::response([], 200),
    ]);
}

function runTriage(YakTask $task, string $state, string $body = '', string $reviewer = 'alice'): void
{
    (new TriageReviewJob($task->id, 500, 9, $state, $body, $reviewer))->handle(app(AppService::class));
}

it('fetches inline comments and reacts with eyes on each', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['none']);
    fakeReviewApi([
        ['id' => 1, 'body' => 'nice', 'path' => 'a.php', 'line' => 3, 'diff_hunk' => '@@', 'user' => ['login' => 'alice']],
        ['id' => 2, 'body' => 'lgtm', 'path' => 'b.php', 'line' => null, 'original_line' => 8, 'diff_hunk' => null, 'user' => ['login' => 'alice']],
    ]);

    runTriage(yakReviewTask(), 'commented');

    Http::assertSentCount(7); // 1 list + 2 eyes + 2 thumbs-up + 2 eyes removed
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls/comments/1/reactions') && json_decode((string) $request->body(), true)['content'] === 'eyes');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls/comments/2/reactions') && json_decode((string) $request->body(), true)['content'] === 'eyes');
});

it('creates a follow-up without asking the agent when changes were requested', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['none']);
    fakeReviewApi([]);

    $task = yakReviewTask();
    runTriage($task, 'changes_requested', 'The retry loop is wrong.');

    $child = YakTask::where('parent_task_id', $task->id)->first();
    expect($child)->not->toBeNull()
        ->and($child->source)->toBe('github')
        ->and($child->author_name)->toBe('alice')
        ->and($child->re_request_review_from)->toBe(['alice'])
        ->and($child->description)->toContain('@alice submitted a review (changes requested)')
        ->and($child->description)->toContain('> The retry loop is wrong.');

    ReviewFeedbackTriage::assertNeverPrompted();
    Queue::assertPushed(RunFollowUpJob::class);
});

it('forces act when an inline comment carries the yak prefix', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['none']);
    fakeReviewApi([
        ['id' => 1, 'body' => '/yak add a test for this', 'path' => 'a.php', 'line' => 3, 'diff_hunk' => null, 'user' => ['login' => 'alice']],
    ]);

    $task = yakReviewTask();
    runTriage($task, 'commented');

    expect(YakTask::where('parent_task_id', $task->id)->exists())->toBeTrue();
    ReviewFeedbackTriage::assertNeverPrompted();
});

it('swaps eyes for thumbs-up and creates nothing when the agent says none', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['none']);
    fakeReviewApi([
        ['id' => 1, 'body' => 'nice work', 'path' => 'a.php', 'line' => 3, 'diff_hunk' => null, 'user' => ['login' => 'alice']],
    ]);

    $task = yakReviewTask();
    runTriage($task, 'commented', 'Looks good.');

    expect(YakTask::where('parent_task_id', $task->id)->exists())->toBeFalse()
        ->and(PendingSteeringMessage::count())->toBe(0);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pulls/comments/1/reactions') && json_decode((string) $request->body(), true)['content'] === '+1');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/pulls/comments/1/reactions/777'));
    Queue::assertNotPushed(RunFollowUpJob::class);
});

it('creates a follow-up on a quiet PR when the agent says act', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['act']);
    fakeReviewApi([
        ['id' => 1, 'body' => 'why a queue here?', 'path' => 'a.php', 'line' => 3, 'diff_hunk' => '@@ -1 +1 @@', 'user' => ['login' => 'alice']],
    ]);

    $task = yakReviewTask();
    runTriage($task, 'commented');

    $child = YakTask::where('parent_task_id', $task->id)->first();
    expect($child)->not->toBeNull()
        ->and($child->description)->toContain('a.php:3 — why a queue here?')
        ->and($child->description)->toContain('@@ -1 +1 @@');
});

it('queues a steering message instead when the PR is busy', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['act']);
    fakeReviewApi([]);

    $root = yakReviewTask();
    YakTask::factory()->running()->create([
        'parent_task_id' => $root->id,
        'pr_url' => $root->pr_url,
        'pr_number' => 9,
        'repo' => 'acme/web',
        'branch_name' => 'yak/CSV-1',
    ]);

    runTriage($root, 'commented', 'Also handle the empty state.');

    expect(YakTask::where('parent_task_id', $root->id)->count())->toBe(1);

    $message = PendingSteeringMessage::first();
    expect($message)->not->toBeNull()
        ->and($message->root_task_id)->toBe($root->id)
        ->and($message->source)->toBe('github_review')
        ->and($message->reviewer_login)->toBe('alice')
        ->and($message->text)->toContain('Also handle the empty state.');
    Queue::assertNotPushed(RunFollowUpJob::class);
});

it('treats an agent failure as act', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(function () {
        throw new RuntimeException('down');
    });
    fakeReviewApi([]);

    $task = yakReviewTask();
    runTriage($task, 'commented', 'hmm');

    expect(YakTask::where('parent_task_id', $task->id)->exists())->toBeTrue();
});

it('does nothing when the PR is no longer open', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['act']);
    fakeReviewApi([]);

    $task = yakReviewTask(['pr_merged_at' => now()]);
    runTriage($task, 'changes_requested', 'too late');

    expect(YakTask::where('parent_task_id', $task->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('tolerates a github error payload when listing review comments', function () {
    Queue::fake();
    ReviewFeedbackTriage::fake(['none']);
    Http::fake([
        'api.github.com/repos/acme/web/pulls/9/reviews/500/comments*' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/*' => Http::response([], 200),
    ]);

    $task = yakReviewTask();
    runTriage($task, 'changes_requested', 'The retry loop is wrong.');

    expect(YakTask::where('parent_task_id', $task->id)->exists())->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/reactions'));
});

it('is unique per review id', function () {
    $job = new TriageReviewJob(1, 500, 9, 'commented', '', 'alice');

    expect($job->uniqueId())->toBe('review:500');
    expect($job->uniqueFor())->toBe(120);
});
