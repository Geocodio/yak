<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerDecisionException;
use App\Services\CoOwner\CoOwnerDecisions;
use App\Services\CoOwner\CoOwnerResolver;
use App\Services\FollowUpTaskFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function decisionFakes(string $role = 'write', array $extra = []): void
{
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + $extra + [
        'api.github.com/user/4242' => Http::response(['login' => 'mhansen']),
        'api.github.com/repos/o/r/collaborators/*/permission' => Http::response(['role_name' => $role]),
        'api.github.com/repos/o/r/pulls?*' => Http::response([]),
        'api.github.com/repos/o/r/pulls' => Http::response(['number' => 7, 'html_url' => 'https://github.com/o/r/pull/7']),
        'api.github.com/repos/o/r/issues/*/labels' => Http::response([]),
        'api.github.com/repos/o/r/pulls/*/requested_reviewers' => Http::response([]),
        'api.github.com/repos/o/r/git/refs/*' => Http::response([], 204),
    ]);
}

beforeEach(function () {
    configureFakeGitHubApp();
    config()->set('yak.pr_title_writer.enabled', false);
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class, RunFollowUpJob::class]);
    $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r', 'default_branch' => 'main']);
    $this->requester = User::factory()->create(['name' => 'Rita Requester']);
    $this->approver = User::factory()->withGitHub()->create(['name' => 'Mathias Hansen']);
    $this->task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => 'o-r',
        'branch_name' => 'yak/x',
        'source' => 'slack',
        'description' => 'Fix auth',
        'started_by_user_id' => $this->requester->id,
    ]);
    $this->request = CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id]);
    decisionFakes();
});

it('opens the PR with the co-owner line, label and reviewer on approve', function () {
    $task = app(CoOwnerDecisions::class)->approve($this->request, $this->approver);

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->pr_url)->toBe('https://github.com/o/r/pull/7')
        ->and($task->responsible_user_id)->toBe($this->approver->id)
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->request->fresh()->decided_by_user_id)->toBe($this->approver->id);
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls')
        && str_contains($r['body'], "CI passed before a co-owner approved this change.\n\n**Source:**")
        && str_contains($r['body'], '**Co-owner:** Mathias Hansen, approved because it touches API middleware and HTTP kernel (high)'));
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/issues/7/labels') && in_array('yak-co-owned', $r['labels'], true));
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/pulls/7/requested_reviewers') && $r['reviewers'] === ['mhansen']);
    Bus::assertDispatched(SendNotificationJob::class, fn ($job): bool => $job->message === 'Approved by Mathias Hansen. PR created: https://github.com/o/r/pull/7');
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job): bool => $job->event === 'resolved');
});

it('labels a large approved change by its additions and deletions', function () {
    $this->request->update(['additions' => 900, 'deletions' => 200]);

    app(CoOwnerDecisions::class)->approve($this->request, $this->approver);

    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/issues/7/labels') && in_array('yak-large-change', $r['labels'], true));
});

it('only labels and requests the approver when the branch already has a PR', function () {
    decisionFakes('write', ['api.github.com/repos/o/r/pulls?*' => Http::response([['number' => 5, 'html_url' => 'https://github.com/o/r/pull/5']])]);

    $task = app(CoOwnerDecisions::class)->approve($this->request, $this->approver);

    expect($task->pr_number)->toBe(5);
    Http::assertNotSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls'));
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/issues/5/labels') && $r['labels'] === ['yak-co-owned']);
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/pulls/5/requested_reviewers'));
});

it('does not fail the approval when the reviewer request fails', function () {
    decisionFakes('write', ['api.github.com/repos/o/r/pulls/*/requested_reviewers' => Http::response(['message' => 'nope'], 422)]);

    $task = app(CoOwnerDecisions::class)->approve($this->request, $this->approver);

    expect($task->status)->toBe(TaskStatus::Success);
});

it('refuses an approver without write access', function () {
    decisionFakes('read');

    expect(fn () => app(CoOwnerDecisions::class)->approve($this->request, $this->approver))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'no_write_access');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner);
    Http::assertNotSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls'));
});

it('refuses an approver who has not linked GitHub', function () {
    expect(fn () => app(CoOwnerDecisions::class)->approve($this->request, User::factory()->create()))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'not_linked');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('reports a failed access check as access_check_failed', function () {
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + ['api.github.com/*' => Http::response([], 500)]);

    expect(fn () => app(CoOwnerDecisions::class)->approve($this->request, $this->approver))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'access_check_failed');
});

it('keeps the request pending when GitHub rejects the PR', function () {
    decisionFakes('write', ['api.github.com/repos/o/r/pulls' => Http::response(['message' => 'Resource not accessible'], 403)]);

    try {
        app(CoOwnerDecisions::class)->approve($this->request, $this->approver);
        $this->fail('Expected a github_failed exception');
    } catch (CoOwnerDecisionException $exception) {
        expect($exception->reason)->toBe('github_failed')
            ->and($exception->status)->toBe(403)
            ->and($exception->getMessage())->toBe('Resource not accessible');
    }

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending)
        ->and($this->request->fresh()->decided_by_user_id)->toBeNull()
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner)
        ->and($this->task->fresh()->responsible_user_id)->toBeNull();
    Bus::assertNotDispatched(SendCoOwnerMessagesJob::class);
});

it('applies only the first of two decisions', function () {
    $decisions = app(CoOwnerDecisions::class);
    $decisions->approve($this->request, $this->approver);

    expect(fn () => $decisions->reject(CoOwnerRequest::find($this->request->id), $this->approver))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'already_resolved');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Success);
});

it('creates a follow-up on request changes and asks the same person next time', function () {
    $followUp = app(CoOwnerDecisions::class)->requestChanges($this->request, $this->approver, 'Also cover the null case');

    expect($followUp->parent_task_id)->toBe($this->task->id)
        ->and($followUp->source)->toBe('slack')
        ->and($followUp->author_name)->toBe('Mathias Hansen')
        ->and($followUp->description)->toBe('Also cover the null case')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::ChangesRequested)
        ->and($this->request->fresh()->feedback)->toBe('Also cover the null case')
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($this->task->fresh()->pr_url)->toBeNull();
    Bus::assertDispatched(RunFollowUpJob::class);
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job): bool => $job->event === 'resolved');

    $resolved = app(CoOwnerResolver::class)->resolve($this->repository, $followUp, []);
    expect($resolved['users'][0]->id)->toBe($this->approver->id);
});

it('deletes the branch on reject and on withdraw', function (string $method) {
    $user = $method === 'reject' ? $this->approver : $this->requester;

    app(CoOwnerDecisions::class)->{$method}($this->request, $user);

    expect($this->request->fresh()->status)->toBe($method === 'reject' ? CoOwnerRequestStatus::Rejected : CoOwnerRequestStatus::Withdrawn)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Cancelled);
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/git/refs/heads/yak/x'));
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job): bool => $job->event === 'resolved');
})->with(['reject', 'withdraw']);

it('lets only the requester withdraw', function () {
    expect(fn () => app(CoOwnerDecisions::class)->withdraw($this->request, $this->approver))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'not_requester');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
    Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
});

it('refuses a reject from a user without write access', function () {
    decisionFakes('read');

    expect(fn () => app(CoOwnerDecisions::class)->reject($this->request, $this->approver))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'no_write_access');
    expect($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner);
});

it('keeps the branch on expire', function () {
    app(CoOwnerDecisions::class)->expire($this->request);

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Expired)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Expired);
    Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job): bool => $job->event === 'resolved');
});

it('supersedes the open request when a thread reply starts a follow-up', function () {
    $followUp = app(FollowUpTaskFactory::class)->create($this->task, 'Please also add a test', 'slack');

    expect($followUp)->not->toBeNull()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Superseded)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Success);
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job): bool => $job->event === 'resolved');
});

it('keeps the approval when a step after the PR opens throws', function () {
    decisionFakes('write', ['api.github.com/repos/o/r/issues/*/labels' => fn () => throw new ConnectionException('boom')]);

    $task = app(CoOwnerDecisions::class)->approve($this->request, $this->approver);

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->pr_url)->toBe('https://github.com/o/r/pull/7')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved);
});

it('does not revert a request another approver now holds', function () {
    $other = User::factory()->withGitHub(5, 'other')->create();
    $request = $this->request;
    decisionFakes('write', ['api.github.com/repos/o/r/pulls' => function () use ($request, $other) {
        CoOwnerRequest::whereKey($request->id)->update(['decided_by_user_id' => $other->id]);

        return Http::response(['message' => 'nope'], 403);
    }]);

    expect(fn () => app(CoOwnerDecisions::class)->approve($this->request, $this->approver))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'github_failed');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->request->fresh()->decided_by_user_id)->toBe($other->id);
});

it('does not supersede a task whose approval is in flight', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::Approved]);

    app(CoOwnerDecisions::class)->supersede($this->task);

    expect($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCoOwner)
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved);
});

it('escapes the responsible name in the PR body', function () {
    $this->approver->update(['name' => 'Ann *Bold*']);

    app(CoOwnerDecisions::class)->approve($this->request, $this->approver);

    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && str_ends_with($r->url(), '/pulls') && str_contains($r['body'], '**Responsible:** Ann \*Bold\*'));
});

it('reverts a failed approval even when the task carries a stale PR URL', function () {
    $this->task->update(['pr_url' => 'https://github.com/o/r/pull/1', 'pr_number' => 1]);
    decisionFakes('write', ['api.github.com/repos/o/r/pulls' => Http::response(['message' => 'nope'], 403)]);

    expect(fn () => app(CoOwnerDecisions::class)->approve($this->request, $this->approver))
        ->toThrow(fn (CoOwnerDecisionException $e) => $e->reason === 'github_failed');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});
