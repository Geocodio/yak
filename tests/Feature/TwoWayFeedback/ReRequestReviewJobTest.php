<?php

use App\Channels\GitHub\AppService;
use App\Enums\TaskStatus;
use App\Jobs\ReRequestReviewJob;
use App\Models\GitHubInstallationToken;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 4242);

    GitHubInstallationToken::create([
        'installation_id' => 4242,
        'token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
});

test('reaching Success dispatches the job when reviewer logins are stored', function () {
    Queue::fake([ReRequestReviewJob::class]);

    $task = YakTask::factory()->awaitingCi()->create(['re_request_review_from' => ['alice']]);

    $task->update(['status' => TaskStatus::Success]);

    Queue::assertPushed(ReRequestReviewJob::class, fn (ReRequestReviewJob $job) => $job->task->id === $task->id);
});

test('reaching Success without reviewer logins dispatches nothing', function () {
    Queue::fake([ReRequestReviewJob::class]);

    $task = YakTask::factory()->awaitingCi()->create();

    $task->update(['status' => TaskStatus::Success]);

    Queue::assertNotPushed(ReRequestReviewJob::class);
});

test('the job asks GitHub to re-request review from the stored logins', function () {
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_number' => 9,
        'pr_url' => 'https://github.com/acme/web/pull/9',
        're_request_review_from' => ['alice', 'bob'],
    ]);

    (new ReRequestReviewJob($task))->handle(app(AppService::class));

    Http::assertSent(function ($request): bool {
        $body = json_decode((string) $request->body(), true);

        return str_ends_with($request->url(), '/repos/acme/web/pulls/9/requested_reviewers')
            && $body['reviewers'] === ['alice', 'bob'];
    });
});

test('a GitHub rejection is logged and does not fail the job', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'nope'], 422)]);
    Log::shouldReceive('channel')->with('yak')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_number' => 9,
        'pr_url' => 'https://github.com/acme/web/pull/9',
        're_request_review_from' => ['alice'],
    ]);

    (new ReRequestReviewJob($task))->handle(app(AppService::class));
});
