<?php

use App\Exceptions\ThreadActionRefused;
use App\Http\Middleware\ClientProtocol;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'client.protocol:app', 'auth.device:phone'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)->group(function () {
        Route::post('/api/app/v1/test-task-refuse', fn () => throw ThreadActionRefused::because(ThreadActionRefused::TASK_NOT_RETRYABLE, 'This task cannot be retried right now.'));
        Route::post('/api/app/v1/test-github-down', fn () => throw new ThreadActionRefused(ThreadActionRefused::GITHUB_UNAVAILABLE, 'Failed to fetch PR from GitHub.', [], 503));
    });
});

it('renders a task refusal with its code and a 503 refusal with its status', function () {
    [, $headers] = phoneWithHeaders();

    $this->postJson('/api/app/v1/test-task-refuse', [], $headers)->assertStatus(409)
        ->assertExactJson(['error' => 'task_not_retryable', 'message' => 'This task cannot be retried right now.']);
    $this->postJson('/api/app/v1/test-github-down', [], $headers)->assertStatus(503)
        ->assertJsonPath('error', 'github_unavailable');
});

it('keeps every new refusal code stable', function () {
    expect([
        ThreadActionRefused::NOTHING_TO_PUBLISH, ThreadActionRefused::CI_NOT_FAILING, ThreadActionRefused::TASK_NOT_RETRYABLE,
        ThreadActionRefused::TASK_NOT_CANCELLABLE, ThreadActionRefused::NOT_A_REVIEW, ThreadActionRefused::REVIEW_ALREADY_QUEUED,
        ThreadActionRefused::PULL_REQUEST_UNKNOWN, ThreadActionRefused::GITHUB_UNAVAILABLE, ThreadActionRefused::NOTHING_TO_RENDER,
        ThreadActionRefused::TASK_NOT_REROUTABLE,
    ])->toBe([
        'nothing_to_publish', 'ci_not_failing', 'task_not_retryable', 'task_not_cancellable', 'not_a_review',
        'review_already_queued', 'pull_request_unknown', 'github_unavailable', 'nothing_to_render', 'task_not_reroutable',
    ]);
});

it('builds task paths with the public id', function () {
    $task = YakTask::factory()->create(['public_id' => 'k3m9p2qa']);

    expect(appTaskPath($task, '/retry'))->toBe('/api/app/v1/tasks/k3m9p2qa/retry');
    expect(appTaskPath($task))->toBe('/api/app/v1/tasks/k3m9p2qa');
});

it('shares a thread with a second person who can read but not drive', function () {
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner);

    $recipient = shareThreadWith($thread, $owner);

    expect($recipient->name)->toBe('Second person')
        ->and(app(ThreadAccess::class)->canSee($recipient, $thread))->toBeTrue()
        ->and(app(ThreadAccess::class)->canDrive($recipient, $thread))->toBeFalse();
});
