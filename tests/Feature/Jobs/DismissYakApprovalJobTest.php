<?php

use App\Channels\GitHub\AppService;
use App\Jobs\DismissYakApprovalJob;
use App\Models\PrReview;
use App\Models\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    configureFakeGitHubApp();
    Repository::factory()->create(['slug' => 'app', 'github_full_name' => 'acme/app']);
    $this->review = PrReview::factory()->create(['repo' => 'app', 'pr_number' => 7, 'github_review_id' => 4242]);
});

it('dismisses the approval and records it', function () {
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/acme/app/pulls/7/reviews/4242/dismissals' => Http::response([])]);

    (new DismissYakApprovalJob($this->review->id))->handle(app(AppService::class));

    expect($this->review->fresh()->dismissed_at)->not->toBeNull();
});

it('succeeds on a retry after a failure', function () {
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/acme/app/pulls/7/reviews/4242/dismissals' => Http::sequence()
        ->push([], 500)->push([], 200)]);
    $job = new DismissYakApprovalJob($this->review->id);

    expect(fn () => $job->handle(app(AppService::class)))->toThrow(RequestException::class);
    expect($this->review->fresh()->dismissed_at)->toBeNull();

    $job->handle(app(AppService::class));

    expect($this->review->fresh()->dismissed_at)->not->toBeNull();
});

it('skips a review that is already dismissed', function () {
    $this->review->update(['dismissed_at' => now()]);
    Http::fake();

    (new DismissYakApprovalJob($this->review->id))->handle(app(AppService::class));

    Http::assertNothingSent();
});

it('retries five times with an increasing backoff', function () {
    $job = new DismissYakApprovalJob($this->review->id);

    expect($job->tries)->toBe(5)->and($job->backoff())->toBe([30, 120, 600, 1800]);
});

it('logs an error after the last attempt fails', function () {
    Log::spy();

    (new DismissYakApprovalJob($this->review->id))->failed(new RuntimeException('422'));

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => ($context['pr_review_id'] ?? null) === $this->review->id);
});

it('stamps a 422 when GitHub reports the review as dismissed', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/pulls/7/reviews/4242/dismissals' => Http::response([], 422),
        'api.github.com/repos/acme/app/pulls/7/reviews/4242' => Http::response(['state' => 'DISMISSED']),
    ]);

    (new DismissYakApprovalJob($this->review->id))->handle(app(AppService::class));

    expect($this->review->fresh()->dismissed_at)->not->toBeNull();
});

it('rethrows a 422 when the review is still approved', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/pulls/7/reviews/4242/dismissals' => Http::response([], 422),
        'api.github.com/repos/acme/app/pulls/7/reviews/4242' => Http::response(['state' => 'APPROVED']),
    ]);

    expect(fn () => (new DismissYakApprovalJob($this->review->id))->handle(app(AppService::class)))
        ->toThrow(RequestException::class);

    expect($this->review->fresh()->dismissed_at)->toBeNull();
});

it('stamps a 422 when the review no longer exists', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/pulls/7/reviews/4242/dismissals' => Http::response([], 422),
        'api.github.com/repos/acme/app/pulls/7/reviews/4242' => Http::response([], 404),
    ]);

    (new DismissYakApprovalJob($this->review->id))->handle(app(AppService::class));

    expect($this->review->fresh()->dismissed_at)->not->toBeNull();
});
