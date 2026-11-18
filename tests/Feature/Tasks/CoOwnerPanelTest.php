<?php

use App\Enums\CoOwnerRequestStatus;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function panelFakes(string $role = 'write', array $extra = []): void
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
    $this->viewer = User::factory()->withGitHub()->create(['name' => 'Mathias Hansen']);
    $this->task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => 'o-r',
        'branch_name' => 'yak/x',
        'source' => 'slack',
        'description' => 'Fix auth',
        'started_by_user_id' => $this->requester->id,
    ]);
    $this->request = CoOwnerRequest::factory()->create([
        'task_id' => $this->task->id,
        'repository_id' => $this->repository->id,
        'asked_user_ids' => [$this->viewer->id],
        'matched_areas' => [[
            'name' => 'API middleware and HTTP kernel',
            'risk' => 'high',
            'rationale' => 'Every request passes through it.',
            'paths' => ['app/Http/Middleware/Authenticate.php'],
        ]],
    ]);
    panelFakes();
    $this->actingAs($this->viewer);
});

it('approves from the dashboard', function () {
    $this->post(route('threads.co-owner.approve', $this->task->thread))
        ->assertRedirect()
        ->assertSessionHas('success', 'Approved. PR #7 is open.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->task->fresh()->pr_url)->toBe('https://github.com/o/r/pull/7');
});

it('returns the GitHub error to the panel', function () {
    panelFakes('write', ['api.github.com/repos/o/r/pulls' => Http::response(['message' => 'Validation Failed'], 422)]);

    $this->post(route('threads.co-owner.approve', $this->task->thread))
        ->assertRedirect()
        ->assertSessionHasErrors('coOwner');

    $error = session('errors')->getBag('default')->get('coOwner')[0];
    expect($error)->toMatchArray(['reason' => 'github_failed', 'status' => 422, 'message' => 'Validation Failed'])
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('refuses an approval from a user without write access', function () {
    panelFakes('read');

    $this->post(route('threads.co-owner.approve', $this->task->thread))->assertSessionHasErrors('coOwner');

    expect(session('errors')->getBag('default')->get('coOwner')[0]['reason'])->toBe('no_write_access')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('requires feedback to request changes', function () {
    $this->post(route('threads.co-owner.request-changes', $this->task->thread), ['feedback' => ''])->assertSessionHasErrors('feedback');
    $this->post(route('threads.co-owner.request-changes', $this->task->thread), ['feedback' => str_repeat('a', 5001)])->assertSessionHasErrors('feedback');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('sends feedback as a follow-up', function () {
    $this->post(route('threads.co-owner.request-changes', $this->task->thread), ['feedback' => 'Use the existing guard.'])
        ->assertSessionHas('success', 'Feedback sent. Yak is running a follow-up.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::ChangesRequested)
        ->and($this->request->fresh()->feedback)->toBe('Use the existing guard.');
});

it('rejects from the dashboard', function () {
    $this->post(route('threads.co-owner.reject', $this->task->thread))->assertSessionHas('success', 'Rejected. The branch was deleted.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected);
});

it('withdraws as the requester', function () {
    $this->actingAs($this->requester)->post(route('threads.co-owner.withdraw', $this->task->thread))
        ->assertSessionHas('success', 'Withdrawn. The branch was deleted.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Withdrawn);
});

it('refuses a withdrawal from someone else', function () {
    $this->post(route('threads.co-owner.withdraw', $this->task->thread))->assertSessionHasErrors('coOwner');

    expect(session('errors')->getBag('default')->get('coOwner')[0]['reason'])->toBe('not_requester')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('rejects a stale action with already_resolved', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::Rejected]);

    $this->post(route('threads.co-owner.approve', $this->task->thread))->assertSessionHasErrors('coOwner');

    expect(session('errors')->getBag('default')->get('coOwner')[0]['reason'])->toBe('already_resolved');
});
