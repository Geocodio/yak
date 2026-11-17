<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerDecisions;
use Illuminate\Support\Facades\Cache;

/**
 * @return array{0: Thread, 1: CoOwnerRequest}
 */
function heldThreadForCoOwner(?User $requester = null, array $askedUserIds = []): array
{
    $repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'geocodio/geocodio']);
    $task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => $repository->slug,
        'started_by_user_id' => $requester?->id,
        'branch_name' => 'yak/api-headers',
    ]);
    $request = CoOwnerRequest::factory()->create([
        'task_id' => $task->id,
        'repository_id' => $repository->id,
        'asked_user_ids' => $askedUserIds,
        'additions' => 84,
        'deletions' => 2,
        'file_count' => 3,
    ]);

    return [Thread::query()->findOrFail($task->thread_id), $request];
}

function grantThreadWriteAccess(CoOwnerRequest $request, User $user, string $access = 'write'): void
{
    Cache::put("co-owner:write-access:{$request->repository_id}:{$user->github_id}", $access, now()->addMinutes(10));
}

it('lets an asked co-owner approve from the card and turns the pull request button into the pull request', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create(['name' => 'Mathias Hansen']);
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(['name' => 'Liam Hansen']), [$viewer->id]);
    grantThreadWriteAccess($request, $viewer);
    $this->mock(CoOwnerDecisions::class, fn ($mock) => $mock->shouldReceive('approve')->once()->andReturnUsing(function (CoOwnerRequest $held) {
        $held->update(['status' => CoOwnerRequestStatus::Approved, 'decided_at' => now()]);
        $held->task->update(['status' => TaskStatus::Success, 'pr_url' => 'https://github.com/geocodio/geocodio/pull/7', 'pr_number' => 7, 'pr_opened_at' => now()]);

        ThreadRepository::query()->where('thread_id', $held->task->thread_id)->update(['pull_request_url' => 'https://github.com/geocodio/geocodio/pull/7', 'pull_request_number' => 7]);

        return $held->task->refresh();
    }));
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-co-owner"]')
        ->assertSeeIn('[data-testid="card-co-owner"]', 'Yak is waiting for a co-owner before it opens the pull request')
        ->assertSeeIn('[data-testid="pull-request-held"]', 'Held for a co-owner')
        ->assertSeeIn('[data-testid="overview-co-owner"]', 'Mathias Hansen')
        ->assertVisible('[data-testid="co-owner-request-changes"]')
        ->assertVisible('[data-testid="co-owner-reject"]')
        ->click('[data-testid="co-owner-approve"]')
        ->wait(2)
        ->assertVisible('[data-testid="thread-header-pr"]')
        ->assertSeeIn('[data-testid="thread-header-pr"]', '#7')
        ->assertMissing('[data-testid="pull-request-held"]')
        ->assertMissing('[data-testid="card-co-owner"]')
        ->assertNoJavaScriptErrors();
});

it('shows the requester a waiting card with withdraw instead of the decision buttons', function () {
    $requester = User::factory()->create();
    $coOwner = User::factory()->withGitHub(11, 'cory')->create(['name' => 'Cory Mawhorter']);
    [$thread] = heldThreadForCoOwner($requester, [$coOwner->id]);
    $this->actingAs($requester);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-co-owner"]')
        ->assertSeeIn('[data-testid="card-co-owner"]', 'Cory Mawhorter was asked.')
        ->assertVisible('[data-testid="card-co-owner"] button:has-text("Withdraw")')
        ->assertMissing('[data-testid="co-owner-approve"]')
        ->assertNoJavaScriptErrors();
});

it('asks a person without a linked GitHub account to connect it, and a read-only one to check access', function () {
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create());
    $unlinked = User::factory()->create();
    $this->actingAs($unlinked);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="co-owner-connect"]')
        ->assertSeeIn('[data-testid="co-owner-connect"]', 'Connect GitHub to approve.')
        ->assertNoJavaScriptErrors();

    $reader = User::factory()->withGitHub(77, 'reader')->create();
    grantThreadWriteAccess($request, $reader, 'read');
    $this->actingAs($reader);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="co-owner-no-access"]')
        ->assertSeeIn('[data-testid="co-owner-no-access"]', 'no write access')
        ->assertNoJavaScriptErrors();
});

it('lets the requester pick a co-owner from the early warning card', function () {
    $requester = User::factory()->create();
    $repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'geocodio/geocodio']);
    $task = YakTask::factory()->create(['repo' => $repository->slug, 'started_by_user_id' => $requester->id, 'early_warning' => ['areas' => [['name' => 'API response pipeline', 'risk' => 'high']], 'state' => 'shown']]);
    $cory = User::factory()->withGitHub(11, 'cory')->create(['name' => 'Cory Mawhorter']);
    Cache::put("co-owner:write-access:{$repository->id}:{$cory->github_id}", 'write', now()->addMinutes(10));
    $this->actingAs($requester);

    visit('/threads/' . Thread::query()->findOrFail($task->thread_id)->public_id)
        ->assertVisible('[data-testid="card-early-warning"]')
        ->assertSeeIn('[data-testid="card-early-warning"]', 'API response pipeline (high risk)')
        ->assertPresent('[data-testid="early-warning-picker"] input[role="combobox"]')
        ->assertNoJavaScriptErrors();
});
