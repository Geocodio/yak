<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerDecisionException;
use App\Services\CoOwner\CoOwnerDecisions;
use Illuminate\Support\Facades\Cache;

/**
 * @return array{0: Thread, 1: CoOwnerRequest}
 */
function heldThreadForCoOwner(?User $requester = null, array $askedUserIds = []): array
{
    $repository = Repository::query()->where('slug', 'o-r')->first() ?? Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'geocodio/geocodio']);
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

    $page = visit('/threads/' . Thread::query()->findOrFail($task->thread_id)->public_id);
    $page->assertVisible('[data-testid="card-early-warning"]')
        ->assertSeeIn('[data-testid="card-early-warning"]', 'API response pipeline (high risk)');

    // The combobox renders once the candidates request has answered.
    waitUntilPresent($page, '[data-testid="early-warning-picker"] input[role="combobox"]');
    $page->assertNoJavaScriptErrors();
});

it('shows a refused decision in the card and keeps the request pending', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(), [$viewer->id]);
    grantThreadWriteAccess($request, $viewer);
    $this->mock(CoOwnerDecisions::class, fn ($mock) => $mock->shouldReceive('approve')->once()->andThrow(CoOwnerDecisionException::alreadyResolved()));
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="co-owner-approve"]')
        ->assertSeeIn('[data-testid="co-owner-error"]', 'Someone already decided this request.')
        ->assertVisible('[data-testid="card-co-owner"]')
        ->assertNoJavaScriptErrors();

    expect($request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('sends feedback from the card with request changes', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(), [$viewer->id]);
    grantThreadWriteAccess($request, $viewer);
    $this->mock(CoOwnerDecisions::class, fn ($mock) => $mock->shouldReceive('requestChanges')->once()->withArgs(fn ($held, $user, string $feedback) => $feedback === 'Split the headers into separate tasks')->andReturnUsing(function (CoOwnerRequest $held) {
        $held->update(['status' => CoOwnerRequestStatus::ChangesRequested, 'decided_at' => now()]);

        return $held->task;
    }));
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="co-owner-request-changes"]')
        ->fill('[data-testid="co-owner-feedback-text"]', 'Split the headers into separate tasks')
        ->click('[data-testid="co-owner-send-feedback"]')
        ->wait(2)
        ->assertMissing('[data-testid="card-co-owner"]')
        ->assertNoJavaScriptErrors();

    expect($request->fresh()->status)->toBe(CoOwnerRequestStatus::ChangesRequested);
});

it('keeps a typed request-changes reason when the phone gets taller', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(), [$viewer->id]);
    grantThreadWriteAccess($request, $viewer);
    $this->actingAs($viewer);

    $page = visit("/threads/{$thread->public_id}");
    $page->resize(375, 667)
        ->click('[data-testid="co-owner-request-changes"]')
        ->fill('[data-testid="co-owner-feedback-text"]', 'Split the headers into separate tasks');
    $page->resize(375, 812)->assertVisible('[data-testid="co-owner-feedback-text"]');

    expect($page->script('document.querySelector(\'[data-testid="co-owner-feedback-text"]\').value'))->toBe('Split the headers into separate tasks');
});

it('rejects after the confirm, and the requester can withdraw', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(), [$viewer->id]);
    grantThreadWriteAccess($request, $viewer);
    $this->mock(CoOwnerDecisions::class, fn ($mock) => $mock->shouldReceive('reject')->once()->andReturnUsing(fn (CoOwnerRequest $held) => $held->update(['status' => CoOwnerRequestStatus::Rejected, 'decided_at' => now()])));
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->click('[data-testid="co-owner-reject"]')
        ->assertSee('Reject this change?')
        ->click('[data-testid="co-owner-reject-confirm"]')
        ->wait(2)
        ->assertMissing('[data-testid="card-co-owner"]')
        ->assertNoJavaScriptErrors();

    expect($request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected);

    $requester = User::factory()->create();
    [$ownThread, $ownRequest] = heldThreadForCoOwner($requester, []);
    $this->mock(CoOwnerDecisions::class, fn ($mock) => $mock->shouldReceive('withdraw')->once()->andReturnUsing(fn (CoOwnerRequest $held) => $held->update(['status' => CoOwnerRequestStatus::Withdrawn, 'decided_at' => now()])));
    $this->actingAs($requester);

    visit("/threads/{$ownThread->public_id}")
        ->click('[data-testid="card-co-owner"] button:has-text("Withdraw")')
        ->wait(2)
        ->assertMissing('[data-testid="card-co-owner"]')
        ->assertNoJavaScriptErrors();

    expect($ownRequest->fresh()->status)->toBe(CoOwnerRequestStatus::Withdrawn);
});

it('shows a person who cannot drive the thread the card without any decision button', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(), [$viewer->id]);
    grantThreadWriteAccess($request, $viewer);
    $thread->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id])->save();
    $thread->shares()->create(['user_id' => $viewer->id]);
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="card-co-owner"]')
        ->assertSeeIn('[data-testid="co-owner-explanation"]', 'This change touches')
        ->assertMissing('[data-testid="co-owner-approve"]')
        ->assertMissing('[data-testid="co-owner-request-changes"]')
        ->assertMissing('[data-testid="co-owner-reject"]')
        ->assertMissing('[data-testid="co-owner-connect"]')
        ->assertNoJavaScriptErrors();
});

it('explains who asked, when and who is next, with approve first', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create(['name' => 'Mathias Hansen']);
    $fallback = User::factory()->create(['name' => 'Fallback Person']);
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(['name' => 'Liam Hansen']), [$viewer->id]);
    $request->repository->update(['default_responsible_user_id' => $fallback->id]);
    grantThreadWriteAccess($request, $viewer);
    $this->actingAs($viewer);

    visit("/threads/{$thread->public_id}")
        ->assertSeeIn('[data-testid="co-owner-explanation"]', 'code that you own.')
        ->assertSeeIn('[data-testid="co-owner-explanation"]', 'Liam asked you')
        ->assertSeeIn('[data-testid="co-owner-explanation"]', 'Yak asks Fallback Person.')
        ->assertDontSee('Held since')
        ->assertNoJavaScriptErrors();
});

it('loads the candidates, picks one and the early warning card goes away', function () {
    $requester = User::factory()->create();
    $repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'geocodio/geocodio']);
    $task = YakTask::factory()->create(['repo' => $repository->slug, 'started_by_user_id' => $requester->id, 'early_warning' => ['areas' => [['name' => 'API response pipeline', 'risk' => 'high']], 'state' => 'shown']]);
    $cory = User::factory()->withGitHub(11, 'cory')->create(['name' => 'Cory Mawhorter']);
    Cache::put("co-owner:write-access:{$repository->id}:{$cory->github_id}", 'write', now()->addMinutes(10));
    $this->actingAs($requester);

    $page = visit('/threads/' . Thread::query()->findOrFail($task->thread_id)->public_id);
    waitUntilPresent($page, '[data-testid="early-warning-picker"] input[role="combobox"]');
    $page->click('[data-testid="early-warning-picker"] input[role="combobox"]')
        ->click('text=Cory Mawhorter, write access');
    waitUntilPresent($page, '[data-testid="card-early-warning"]', isPresent: false);
    $page->assertNoJavaScriptErrors();

    expect($task->fresh()->tagged_co_owner_user_id)->toBe($cory->id);
});

it('keeps the composer and its Send button on screen at 375 x 667 with the co-owner card above it', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    [$thread, $request] = heldThreadForCoOwner(User::factory()->create(), [$viewer->id]);
    grantThreadWriteAccess($request, $viewer);
    $this->mock(CoOwnerDecisions::class, fn ($mock) => $mock->shouldReceive('approve')->once());
    $this->actingAs($viewer);

    $page = visit("/threads/{$thread->public_id}");
    $page->resize(375, 667)->assertVisible('[data-testid="thread-cards"]')->assertVisible('[data-testid="thread-send"]');

    $fits = $page->script(<<<'JS'
        (async () => {
            const measure = () => ['thread-composer', 'thread-composer-input', 'thread-send'].map((testId) => {
                const rect = document.querySelector('[data-testid="' + testId + '"]').getBoundingClientRect();
                return [testId, rect.top >= 0 && rect.bottom <= window.innerHeight + 1 && rect.left >= 0 && rect.right <= window.innerWidth + 1];
            });
            for (let attempt = 0; attempt < 60 && ! measure().every(([, fits]) => fits); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return measure();
        })()
    JS);

    expect($fits)->toBe([['thread-composer', true], ['thread-composer-input', true], ['thread-send', true]])
        ->and($page->script('document.documentElement.scrollHeight <= window.innerHeight + 1'))->toBeTrue();

    $page->assertVisible('[data-testid="co-owner-approve"]')->click('[data-testid="co-owner-approve"]')->assertNoJavaScriptErrors();
});
