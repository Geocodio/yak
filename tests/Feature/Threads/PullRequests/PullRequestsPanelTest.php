<?php

use App\Enums\ThreadVisibility;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\VisibilityWorld;

function panelThread(): array
{
    Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api']);
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web']);
    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['owner_user_id' => $owner->id, 'visibility' => ThreadVisibility::Team]);
    $api = ThreadRepository::factory()->for($thread)->withPullRequest(412)->create(['repo' => 'acme/api', 'pull_request_title' => 'Paginate invoices', 'ci_status' => 'passing', 'merge_position' => 1]);
    $web = ThreadRepository::factory()->for($thread)->changes()->withPullRequest(188)->create(['repo' => 'acme/web', 'pull_request_title' => 'Load invoices', 'ci_status' => 'running', 'merge_position' => 2, 'merge_reason' => 'This web app calls the new API.']);

    return [$thread, $owner, $api, $web];
}

it('lists changes repos as pending while no pull request exists', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['owner_user_id' => $owner->id, 'visibility' => ThreadVisibility::Team]);
    ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/api', 'branch' => 'yak/x', 'ci_status' => 'running']);
    ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/web']);

    $body = $this->actingAs($owner)->getJson("/threads/{$thread->id}/pull-requests")->assertOk()->json();

    expect($body['state'])->toBe('none')->and($body['banner']['title'])->toBe('No pull requests yet')
        ->and(collect($body['withoutPullRequest'])->pluck('reason', 'repo')->all())->toBe(['acme/api' => 'pending', 'acme/web' => 'unchanged']);
});

it('waits for ci and chains merge after for open rows', function () {
    [$thread, $owner] = panelThread();

    $body = $this->actingAs($owner)->getJson("/threads/{$thread->id}/pull-requests")->assertOk()->json();

    expect($body['state'])->toBe('waiting_for_ci')->and($body['banner']['title'])->toBe('Waiting for CI')
        ->and($body['openCount'])->toBe(2)->and($body['totalCount'])->toBe(2)
        ->and($body['pullRequests'][0]['repoInitials'])->toBe('AP')->and($body['pullRequests'][0]['mergeAfter'])->toBeNull()
        ->and($body['pullRequests'][1]['mergeAfter'])->toBe(['repo' => 'acme/api', 'number' => 412, 'reason' => 'This web app calls the new API.']);
});

it('is partial when one pull request merged', function () {
    [$thread, $owner, $api] = panelThread();
    $api->forceFill(['pull_request_state' => 'merged'])->save();

    $body = $this->actingAs($owner)->getJson("/threads/{$thread->id}/pull-requests")->json();

    expect($body['state'])->toBe('partial')->and($body['openCount'])->toBe(1)
        ->and($body['pullRequests'][0]['state'])->toBe('merged')->and($body['pullRequests'][1]['mergeAfter'])->toBeNull();
});

it('is blocked when an open row has failing ci and offers to fix it', function () {
    [$thread, $owner, , $web] = panelThread();
    $web->forceFill(['ci_status' => 'failing', 'ci_checks' => [
        ['name' => 'tests', 'state' => 'failing', 'durationSeconds' => 160, 'excerpt' => 'boom'],
        ['name' => 'lint', 'state' => 'passing', 'durationSeconds' => 28, 'excerpt' => null],
    ]])->save();

    $body = $this->actingAs($owner)->getJson("/threads/{$thread->id}/pull-requests")->json();

    expect($body['state'])->toBe('blocked')->and($body['banner']['tone'])->toBe('danger')->and($body['banner']['title'])->toBe('Blocked: CI is failing in acme/web')
        ->and($body['pullRequests'][1]['canAskToFix'])->toBeTrue()->and($body['pullRequests'][0]['canAskToFix'])->toBeFalse()
        ->and($body['pullRequests'][1]['ci']['passedChecks'])->toBe(1)->and($body['pullRequests'][1]['ci']['totalChecks'])->toBe(2);
});

it('lists a context repo without a pull request', function () {
    [$thread, $owner] = panelThread();
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs']);

    $body = $this->actingAs($owner)->getJson("/threads/{$thread->id}/pull-requests")->json();

    expect($body['totalCount'])->toBe(2)->and($body['withoutPullRequest'])->toHaveCount(1)->and($body['withoutPullRequest'][0]['reason'])->toBe('context');
});

it('updates positions on reorder and rejects bad ids', function () {
    Queue::fake();
    [$thread, $owner, $api, $web] = panelThread();

    $this->actingAs($owner)->putJson("/threads/{$thread->id}/pull-requests/order", ['order' => [$web->id, $api->id]])->assertOk();
    expect($web->refresh()->merge_position)->toBe(1)->and($api->refresh()->merge_position)->toBe(2);

    $this->actingAs($owner)->putJson("/threads/{$thread->id}/pull-requests/order", ['order' => [$api->id, 999999]])->assertUnprocessable();
    $this->actingAs($owner)->putJson("/threads/{$thread->id}/pull-requests/order", ['order' => [$api->id]])->assertUnprocessable();
    expect($web->refresh()->merge_position)->toBe(1);
});

it('dispatches a retry job without spending the retry budget', function () {
    Queue::fake();
    [$thread, $owner, , $web] = panelThread();
    $web->forceFill(['ci_status' => 'failing', 'ci_retry_count' => 3, 'ci_checks' => [['name' => 'tests', 'state' => 'failing', 'excerpt' => 'boom']]])->save();

    $this->actingAs($owner)->postJson("/threads/{$thread->id}/pull-requests/{$web->id}/fix")->assertStatus(202);

    Queue::assertPushed(RetryRepositoryCiJob::class, fn ($job) => $job->threadRepositoryId === $web->id && str_contains((string) $job->output, 'boom'));
    expect($web->refresh()->ci_retry_count)->toBe(3);
});

it('does not let a viewer of a private thread reorder or ask to fix', function () {
    Queue::fake();
    [$thread, , $api, $web] = panelThread();
    $viewer = User::factory()->create();
    $thread->forceFill(['visibility' => ThreadVisibility::Private])->save();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->getJson("/threads/{$thread->id}/pull-requests")->assertOk()->assertJsonPath('canDrive', false);
    $this->actingAs($viewer)->putJson("/threads/{$thread->id}/pull-requests/order", ['order' => [$web->id, $api->id]])->assertForbidden();
    $this->actingAs($viewer)->postJson("/threads/{$thread->id}/pull-requests/{$web->id}/fix")->assertForbidden();
    Queue::assertNothingPushed();
});

it('answers a private thread like a missing one for another user and an admin', function (string $stranger) {
    $world = VisibilityWorld::build();
    $row = $world->pullRequestRow->id;
    $calls = [
        ['GET', '/threads/%s/pull-requests', []],
        ['PUT', '/threads/%s/pull-requests/order', ['order' => [$row]]],
        ['POST', "/threads/%s/pull-requests/{$row}/fix", []],
    ];

    foreach ($calls as [$method, $uri, $payload]) {
        $hidden = $this->actingAs($world->{$stranger})->json($method, sprintf($uri, $world->thread->id), $payload);
        $missing = $this->actingAs($world->{$stranger})->json($method, sprintf($uri, $world->missingId), $payload);

        expect($hidden->getStatusCode())->toBe(404)->and($hidden->json('message'))->toContain('No query results for model')->and($hidden->getContent())->not->toContain(VisibilityWorld::TITLE);
    }
})->with(['other', 'admin']);
