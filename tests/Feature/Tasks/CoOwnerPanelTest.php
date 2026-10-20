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
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;

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

it('shares the co-owner panel with viewer access', function (string $role, string $access) {
    panelFakes($role);

    $this->get(route('tasks.show', $this->task))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('coOwner.id', $this->request->id)
            ->where('coOwner.status', 'pending')
            ->where('coOwner.repoName', 'o/r')
            ->where('coOwner.branch', 'yak/x')
            ->where('coOwner.compareUrl', 'https://github.com/o/r/compare/main...yak/x')
            ->where('coOwner.prUrl', null)
            ->where('coOwner.areas.0.name', 'API middleware and HTTP kernel')
            ->where('coOwner.areas.0.risk', 'high')
            ->where('coOwner.areas.0.files.0', ['path' => 'app/Http/Middleware/Authenticate.php', 'status' => 'modified'])
            ->where('coOwner.stat', ['additions' => 42, 'deletions' => 3, 'files' => 2])
            ->where('coOwner.asked', ['Mathias Hansen'])
            ->where('coOwner.requesterName', 'Rita Requester')
            ->where('coOwner.viewer.isRequester', false)
            ->where('coOwner.viewer.access', $access)
            ->where('coOwner.viewer.login', 'mhansen')
            ->where('coOwner.connectUrl', route('settings.github.connect', ['return' => "/tasks/{$this->task->id}"]))
            ->where('coOwner.linkError', null)
            ->where('task.nextSteps', null)
            ->where('composer.state', 'held')
            ->where('actions.canCancel', false));
})->with([['write', 'write'], ['read', 'read']]);

it('shares an unlinked viewer without calling GitHub', function () {
    $this->actingAs(User::factory()->create());
    Http::swap(new Factory);
    Http::fake();

    $this->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('coOwner.viewer.access', 'unlinked')
            ->where('coOwner.viewer.login', null));
    Http::assertNothingSent();
});

it('shows a failed GitHub link on the panel', function () {
    $this->withSession(['github_link_return' => "/tasks/{$this->task->id}"])
        ->followingRedirects()
        ->get(route('settings.github.callback', ['error' => 'access_denied']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tasks/Show')
            ->where('coOwner.linkError', ['kind' => 'failed', 'code' => 'access_denied', 'ownerName' => null, 'ownerLogin' => null]));
});

it('shows a taken GitHub account on the panel', function () {
    User::factory()->withGitHub(777, 'dana')->create(['name' => 'Dana Owner']);
    Socialite::fake('github', (new Laravel\Socialite\Two\User)->map(['id' => 777, 'nickname' => 'dana', 'name' => 'Dana', 'token' => 'gho_x']));

    $this->withSession(['github_link_return' => "/tasks/{$this->task->id}"])
        ->followingRedirects()
        ->get(route('settings.github.callback'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('coOwner.linkError', ['kind' => 'taken', 'code' => null, 'ownerName' => 'Dana Owner', 'ownerLogin' => 'dana']));
});

it('approves from the dashboard', function () {
    $this->post(route('tasks.co-owner.approve', $this->task))
        ->assertRedirect()
        ->assertSessionHas('success', 'Approved. PR #7 is open.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->task->fresh()->pr_url)->toBe('https://github.com/o/r/pull/7');
});

it('returns the GitHub error to the panel', function () {
    panelFakes('write', ['api.github.com/repos/o/r/pulls' => Http::response(['message' => 'Validation Failed'], 422)]);

    $this->post(route('tasks.co-owner.approve', $this->task))
        ->assertRedirect()
        ->assertSessionHasErrors('coOwner');

    $error = session('errors')->getBag('default')->get('coOwner')[0];
    expect($error)->toMatchArray(['reason' => 'github_failed', 'status' => 422, 'message' => 'Validation Failed'])
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('refuses an approval from a user without write access', function () {
    panelFakes('read');

    $this->post(route('tasks.co-owner.approve', $this->task))->assertSessionHasErrors('coOwner');

    expect(session('errors')->getBag('default')->get('coOwner')[0]['reason'])->toBe('no_write_access')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('requires feedback to request changes', function () {
    $this->post(route('tasks.co-owner.request-changes', $this->task), ['feedback' => ''])->assertSessionHasErrors('feedback');
    $this->post(route('tasks.co-owner.request-changes', $this->task), ['feedback' => str_repeat('a', 5001)])->assertSessionHasErrors('feedback');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('sends feedback as a follow-up', function () {
    $this->post(route('tasks.co-owner.request-changes', $this->task), ['feedback' => 'Use the existing guard.'])
        ->assertSessionHas('success', 'Feedback sent. Yak is running a follow-up.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::ChangesRequested)
        ->and($this->request->fresh()->feedback)->toBe('Use the existing guard.');
});

it('rejects from the dashboard', function () {
    $this->post(route('tasks.co-owner.reject', $this->task))->assertSessionHas('success', 'Rejected. The branch was deleted.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected);
});

it('withdraws as the requester', function () {
    $this->actingAs($this->requester)->post(route('tasks.co-owner.withdraw', $this->task))
        ->assertSessionHas('success', 'Withdrawn. The branch was deleted.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Withdrawn);
});

it('refuses a withdrawal from someone else', function () {
    $this->post(route('tasks.co-owner.withdraw', $this->task))->assertSessionHasErrors('coOwner');

    expect(session('errors')->getBag('default')->get('coOwner')[0]['reason'])->toBe('not_requester')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('rejects a stale action with already_resolved', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::Rejected]);

    $this->post(route('tasks.co-owner.approve', $this->task))->assertSessionHasErrors('coOwner');

    expect(session('errors')->getBag('default')->get('coOwner')[0]['reason'])->toBe('already_resolved');
});

it('forgets the cached access on check again', function () {
    $this->get(route('tasks.show', $this->task));
    $key = "co-owner:write-access:{$this->repository->id}:4242";
    expect(Cache::has($key))->toBeTrue();

    $this->post(route('tasks.co-owner.check-access', $this->task))->assertRedirect();

    expect(Cache::has($key))->toBeFalse();
});

it('lists the waiting step in progress', function () {
    $this->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('progress.steps.4.label', 'CI passing')
            ->where('progress.steps.5.label', 'Waiting for a co-owner')
            ->where('progress.steps.5.current', true)
            ->where('progress.steps.4.done', true)
            ->where('progress.steps.6.label', 'Pull request')
            ->where('progress.steps.6.done', false)
            ->where('progress.steps.7.label', 'Done'));
});

it('resolves the latest request of a request-changes chain from the root', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::ChangesRequested]);
    $this->task->update(['status' => TaskStatus::Success]);
    $followUp = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => 'o-r', 'parent_task_id' => $this->task->id, 'source' => 'slack', 'branch_name' => 'yak/x', 'started_by_user_id' => $this->requester->id,
    ]);
    $latest = CoOwnerRequest::factory()->create([
        'task_id' => $followUp->id, 'root_task_id' => $this->task->id, 'repository_id' => $this->repository->id,
    ]);

    $this->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('coOwner.id', $latest->id)
            ->where('composer.state', 'held')
            ->where('progress.steps.5.label', 'Waiting for a co-owner')
            ->where('progress.steps.5.current', true));

    $this->post(route('tasks.co-owner.approve', $this->task))->assertSessionHas('success');

    expect($latest->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::ChangesRequested);
});

it('explains an expired request and offers no retry', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::Expired, 'decided_at' => now()]);
    $this->task->update(['status' => TaskStatus::Expired]);

    $this->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.nextSteps', 'The co-owner request expired after 7 days. Start a new task to try again.')
            ->where('actions.canRetry', false)
            ->where('coOwner.status', 'expired'));
});

it('explains an expired child request on the root page and offers no retry', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::ChangesRequested]);
    $this->task->update(['status' => TaskStatus::Success]);
    $followUp = YakTask::factory()->expired()->create([
        'repo' => 'o-r', 'parent_task_id' => $this->task->id, 'source' => 'slack', 'branch_name' => 'yak/x',
    ]);
    CoOwnerRequest::factory()->create([
        'task_id' => $followUp->id, 'root_task_id' => $this->task->id, 'repository_id' => $this->repository->id,
        'status' => CoOwnerRequestStatus::Expired,
    ]);

    $this->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('task.nextSteps', 'The co-owner request expired after 7 days. Start a new task to try again.')
            ->where('actions.canRetry', false));
});

it('keeps the root status for a follow-up conversation without a request', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'repo' => 'o-r']);
    YakTask::factory()->running()->create(['repo' => 'o-r', 'parent_task_id' => $root->id]);

    $this->get(route('tasks.show', $root))
        ->assertInertia(fn (Assert $page) => $page
            ->where('coOwner', null)
            ->where('progress.steps.6.label', 'Done')
            ->where('progress.steps.6.current', true)
            ->where('task.nextSteps', null)
            ->where('actions.canCancel', false)
            ->where('actions.canRetry', false));
});

it('shares no panel for a task without a request', function () {
    $plain = YakTask::factory()->create(['status' => TaskStatus::Success]);

    $this->get(route('tasks.show', $plain))->assertInertia(fn (Assert $page) => $page->where('coOwner', null)->where('progress.steps.5.label', 'Pull request'));
});

it('lists the asked people in the order they were asked', function () {
    $second = User::factory()->create(['name' => 'Zed Zebra']);
    $this->request->update(['asked_user_ids' => [$second->id, $this->viewer->id]]);

    $this->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page->where('coOwner.asked', ['Zed Zebra', 'Mathias Hansen']));
});

it('looks up a Slack requester without a Yak account once across renders', function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    $this->task->update(['started_by_user_id' => null, 'slack_user_id' => 'UGHOSTREQ']);
    Http::fake(['https://slack.com/api/*' => Http::response(['ok' => false, 'error' => 'user_not_found'])]);

    $this->get(route('tasks.show', $this->task))->assertOk();
    $this->get(route('tasks.show', $this->task))->assertOk();

    expect(Http::recorded(fn ($request): bool => str_contains($request->url(), 'users.info')))->toHaveCount(1);
});

it('treats a viewer whose Slack id started the task as the requester without a lookup', function () {
    $this->viewer->update(['slack_user_id' => 'UVIEW']);
    $this->task->update(['started_by_user_id' => null, 'slack_user_id' => 'UVIEW']);
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + ['api.github.com/*' => Http::response(['role_name' => 'write'])]);

    $this->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page->where('coOwner.viewer.isRequester', true)->where('coOwner.requesterName', 'Mathias Hansen'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'slack.com'));
});
