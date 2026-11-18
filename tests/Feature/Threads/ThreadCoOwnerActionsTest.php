<?php

use App\Enums\CoOwnerRequestStatus;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function threadCoOwnerFakes(string $role = 'write'): void
{
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + [
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
    $this->withoutVite();
    configureFakeGitHubApp();
    config()->set('yak.pr_title_writer.enabled', false);
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class, RunFollowUpJob::class]);
    $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r', 'default_branch' => 'main']);
    $this->requester = User::factory()->create(['name' => 'Rita Requester']);
    $this->coOwner = User::factory()->withGitHub()->create(['name' => 'Mathias Hansen']);
    $this->task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => 'o-r',
        'branch_name' => 'yak/x',
        'source' => 'slack',
        'description' => 'Fix auth',
        'started_by_user_id' => $this->requester->id,
    ]);
    $this->thread = Thread::query()->findOrFail($this->task->thread_id);
    $this->request = CoOwnerRequest::factory()->create([
        'task_id' => $this->task->id,
        'repository_id' => $this->repository->id,
        'asked_user_ids' => [$this->coOwner->id],
    ]);
    threadCoOwnerFakes();
});

function threadCoOwnerError(string $bag = 'coOwner'): array
{
    return session('errors')->getBag('default')->get($bag)[0];
}

it('approves from the thread and opens the pull request', function () {
    $this->actingAs($this->coOwner)->post(route('threads.co-owner.approve', $this->thread))
        ->assertRedirect(route('threads.show', $this->thread))
        ->assertSessionHas('success', 'Approved. PR #7 is open.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->task->fresh()->pr_url)->toBe('https://github.com/o/r/pull/7');
});

it('refuses an approval and a rejection from a person without write access like the task route', function (string $action) {
    threadCoOwnerFakes('read');

    $this->actingAs($this->coOwner)->post(route("threads.co-owner.{$action}", $this->thread))
        ->assertRedirect(route('threads.show', $this->thread))
        ->assertSessionHasErrors('coOwner');

    expect(threadCoOwnerError()['reason'])->toBe('no_write_access')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
})->with(['approve', 'reject']);

it('requires feedback to request changes and sends it as a follow-up', function () {
    $this->actingAs($this->coOwner)->post(route('threads.co-owner.request-changes', $this->thread), ['feedback' => ''])->assertSessionHasErrors('feedback');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);

    $this->actingAs($this->coOwner)->post(route('threads.co-owner.request-changes', $this->thread), ['feedback' => 'Use the existing guard.'])
        ->assertSessionHas('success', 'Feedback sent. Yak is running a follow-up.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::ChangesRequested)
        ->and($this->request->fresh()->feedback)->toBe('Use the existing guard.');
});

it('rejects from the thread', function () {
    $this->actingAs($this->coOwner)->post(route('threads.co-owner.reject', $this->thread))->assertSessionHas('success', 'Rejected. The branch was deleted.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected);
});

it('lets only the requester withdraw', function () {
    $this->actingAs($this->coOwner)->post(route('threads.co-owner.withdraw', $this->thread))->assertSessionHasErrors('coOwner');
    expect(threadCoOwnerError()['reason'])->toBe('not_requester')
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);

    $this->actingAs($this->requester)->post(route('threads.co-owner.withdraw', $this->thread))->assertSessionHas('success', 'Withdrawn. The branch was deleted.');
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Withdrawn);
});

it('rejects a stale decision with already_resolved', function () {
    $this->request->update(['status' => CoOwnerRequestStatus::Rejected]);

    $this->actingAs($this->coOwner)->post(route('threads.co-owner.approve', $this->thread))->assertSessionHasErrors('coOwner');

    expect(threadCoOwnerError()['reason'])->toBe('already_resolved');
});

it('forgets the cached write access on check again', function () {
    $key = "co-owner:write-access:{$this->repository->id}:4242";
    Cache::put($key, 'read', now()->addMinutes(10));

    $this->actingAs($this->coOwner)->post(route('threads.co-owner.check-access', $this->thread))->assertRedirect(route('threads.show', $this->thread));

    expect(Cache::has($key))->toBeFalse();
});

it('decides on the task holding the latest request, not the head task', function () {
    $head = YakTask::factory()->create(['thread_id' => $this->thread->id, 'parent_task_id' => $this->task->id, 'repo' => 'o-r']);

    $this->actingAs($this->coOwner)->post(route('threads.co-owner.reject', $this->thread))->assertSessionHas('success');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected)
        ->and($head->fresh()->status)->not->toBe($this->task->fresh()->status);
});

it('answers a thread without a co-owner request with the nothing-to-decide sentence', function (string $action) {
    $thread = Thread::factory()->create(['repo' => 'o-r']);
    YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => 'o-r']);

    $this->actingAs($this->coOwner)->postJson(route("threads.co-owner.{$action}", $thread), ['feedback' => 'x'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('thread');
    $this->actingAs($this->coOwner)->post(route("threads.co-owner.{$action}", $thread), ['feedback' => 'x'])
        ->assertSessionHas('error', 'This thread has nothing to decide.');
})->with(['approve', 'request-changes', 'reject', 'withdraw', 'check-access']);

it('refuses a closed thread', function () {
    $this->thread->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'merged']);

    $this->actingAs($this->coOwner)->post(route('threads.co-owner.approve', $this->thread))->assertSessionHas('error', 'This thread is closed.');

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

dataset('co-owner routes', [
    ['post', 'threads.co-owner.approve'],
    ['post', 'threads.co-owner.request-changes'],
    ['post', 'threads.co-owner.reject'],
    ['post', 'threads.co-owner.withdraw'],
    ['post', 'threads.co-owner.check-access'],
    ['post', 'threads.co-owner.tag'],
    ['post', 'threads.co-owner.decline'],
    ['get', 'threads.co-owner.candidates'],
]);

it('refuses a viewer who cannot drive and hides the thread from a stranger', function (string $method, string $name) {
    $this->thread->forceFill(['visibility' => 'private', 'owner_user_id' => $this->requester->id])->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $this->thread->id, 'user_id' => $viewer->id]);
    $payload = ['feedback' => 'x', 'user_id' => $this->coOwner->id];

    $this->actingAs($viewer)->{$method}(route($name, $this->thread), $payload)->assertForbidden();
    $this->actingAs($this->coOwner)->{$method}(route($name, $this->thread), $payload)->assertNotFound();

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
})->with('co-owner routes');

describe('early warning', function () {
    beforeEach(function () {
        $this->warned = YakTask::factory()->create([
            'repo' => 'o-r',
            'started_by_user_id' => $this->requester->id,
            'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown'],
        ]);
        $this->warnedThread = Thread::query()->findOrFail($this->warned->thread_id);
        $this->writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
        Cache::put("co-owner:write-access:{$this->repository->id}:1", 'write', now()->addMinutes(10));
    });

    it('lets the requester tag a co-owner from the thread', function () {
        $this->actingAs($this->requester)->post(route('threads.co-owner.tag', $this->warnedThread), ['user_id' => $this->writer->id])
            ->assertRedirect(route('threads.show', $this->warnedThread))
            ->assertSessionHasNoErrors();

        expect($this->warned->fresh())->tagged_co_owner_user_id->toBe($this->writer->id)->early_warning->toMatchArray(['state' => 'tagged']);
    });

    it('refuses a tag from a person who can drive the thread but did not start it', function () {
        $this->actingAs($this->writer)->post(route('threads.co-owner.tag', $this->warnedThread), ['user_id' => $this->writer->id])
            ->assertSessionHasErrors('earlyWarning');

        expect(threadCoOwnerError('earlyWarning')['reason'])->toBe('not_requester')
            ->and($this->warned->fresh()->tagged_co_owner_user_id)->toBeNull();
    });

    it('lets only the requester decline', function () {
        $this->actingAs($this->writer)->post(route('threads.co-owner.decline', $this->warnedThread))->assertSessionHasErrors('earlyWarning');
        expect($this->warned->fresh()->early_warning['state'])->toBe('shown');

        $this->actingAs($this->requester)->post(route('threads.co-owner.decline', $this->warnedThread))->assertSessionHasNoErrors();
        expect($this->warned->fresh()->early_warning['state'])->toBe('declined');
    });

    it('lists the candidates for the requester and none for anyone else', function () {
        $this->actingAs($this->requester)->getJson(route('threads.co-owner.candidates', $this->warnedThread))
            ->assertOk()
            ->assertJsonFragment(['id' => $this->writer->id, 'name' => 'Wanda Writer']);

        $this->actingAs($this->writer)->getJson(route('threads.co-owner.candidates', $this->warnedThread))
            ->assertOk()
            ->assertExactJson(['candidates' => [], 'isComplete' => true]);
    });

    it('answers a thread without a task with the nothing-to-decide sentence', function () {
        $empty = Thread::factory()->create(['repo' => 'o-r']);

        $this->actingAs($this->requester)->postJson(route('threads.co-owner.decline', $empty))->assertStatus(422);
        $this->actingAs($this->requester)->getJson(route('threads.co-owner.candidates', $empty))->assertStatus(422);
    });
});
