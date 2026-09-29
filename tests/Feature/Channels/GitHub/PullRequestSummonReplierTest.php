<?php

use App\Channels\GitHub\PullRequestSummonReplier;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 99);
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 't', 'expires_at' => now()->addHour()]);
});

it('replies in the review thread when a thread is given', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);

    app(PullRequestSummonReplier::class)->reply('acme/web', 9, 555, 'On it.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/555/replies')
        && $request['body'] === 'On it.');
});

it('falls back to a PR comment when the thread reply fails', function () {
    Http::fake([
        'api.github.com/repos/acme/web/pulls/9/comments/555/replies' => Http::response(['message' => 'nope'], 422),
        'api.github.com/*' => Http::response(['id' => 1], 201),
    ]);

    app(PullRequestSummonReplier::class)->reply('acme/web', 9, 555, 'On it.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/9/comments'));
});

it('replies for a task with a link to the task', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web']);
    $task = YakTask::factory()->create(['repo' => 'web', 'pr_number' => 9, 'summon_review_comment_id' => null]);

    app(PullRequestSummonReplier::class)->replyForTask($task, 'Done.');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/9/comments')
        && str_contains($request['body'], 'Done.')
        && str_contains($request['body'], route('tasks.show', $task)));
});
