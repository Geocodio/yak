<?php

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerDecisionException;
use App\Services\CoOwner\CoOwnerDecisions;
use Illuminate\Support\Facades\Cache;

/**
 * @param  array<string, mixed>  $requestAttributes
 */
function heldCoOwnerRequest(array $requestAttributes = [], ?User $requester = null): CoOwnerRequest
{
    $repository = Repository::factory()->create(['github_full_name' => 'geocodio/geocodio']);
    $task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => $repository->slug,
        'started_by_user_id' => $requester?->id,
        'branch_name' => 'yak/api-headers',
    ]);

    return CoOwnerRequest::factory()->create([
        'task_id' => $task->id,
        'repository_id' => $repository->id,
        'additions' => 84,
        'deletions' => 2,
        'file_count' => 3,
        ...$requestAttributes,
    ]);
}

function grantWriteAccess(CoOwnerRequest $request, User $user, string $access = 'write'): void
{
    Cache::put("co-owner:write-access:{$request->repository_id}:{$user->github_id}", $access, now()->addMinutes(10));
}

it('shows approve controls to a co-owner with write access', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    $request = heldCoOwnerRequest();
    grantWriteAccess($request, $viewer);
    $this->actingAs($viewer);

    visit(route('tasks.show', $request->task_id))
        ->assertSee('Needs co-owner')
        ->assertDontSee('awaiting co owner')
        ->assertSee('This change needs a co-owner')
        ->assertSee('A co-owner is a second person with write access to geocodio/geocodio.')
        ->assertSee('Matched area')
        ->assertSee('API middleware and HTTP kernel')
        ->assertSee('High risk')
        ->assertSee('Why this area is sensitive')
        ->assertSee('+84 −2 across 3 files')
        ->assertSee('CI passed')
        ->assertSee('View changes on GitHub')
        ->assertSee('Write access to geocodio/geocodio confirmed as mhansen. Approving makes you the responsible reviewer.')
        ->assertSee('Approve & open PR')
        ->click('[data-testid="co-owner-request-changes"]')
        ->assertSee('What should Yak change?')
        ->assertSee('No PR exists yet, so nothing is merged or visible to customers while Yak revises.')
        ->assertSee('Send feedback to Yak')
        ->click('[data-testid="co-owner-reject"]')
        ->assertSee('Reject this change?')
        ->assertSee('Yak deletes the branch yak/api-headers and no PR opens.')
        ->assertNoJavascriptErrors();
});

it('shows the requester view with withdraw', function () {
    $requester = User::factory()->create();
    $asked = User::factory()->withGitHub(7, 'dana')->create(['name' => 'Dana Whitfield']);
    $request = heldCoOwnerRequest(['asked_user_ids' => [$asked->id]], $requester);
    $this->actingAs($requester);

    visit(route('tasks.show', $request->task_id))
        ->assertSee('Thanks for the request. Your branch is built and CI passed.')
        ->assertSee('Waiting for a co-owner')
        ->assertSee('Dana Whitfield was asked. Anyone with write access to geocodio/geocodio can approve.')
        ->assertSee('Share the link if you know someone who can take a look.')
        ->assertSee('Withdraw')
        ->assertSee('Copy link to share')
        ->assertDontSee('Approve & open PR')
        ->assertNoJavascriptErrors();
});

it('shows the connect prompt to an unlinked viewer', function () {
    $this->actingAs(User::factory()->create());
    $request = heldCoOwnerRequest();

    visit(route('tasks.show', $request->task_id))
        ->assertSee('Connect GitHub to approve. Yak checks you have write access to geocodio/geocodio.')
        ->assertSee('Connect GitHub')
        ->assertSee('Request changes')
        ->assertSee('Reject')
        ->assertDontSee('Approve & open PR')
        ->assertNoJavascriptErrors();
});

it('shows the no-access state with check again', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    $request = heldCoOwnerRequest();
    grantWriteAccess($request, $viewer, 'read');
    $this->actingAs($viewer);

    visit(route('tasks.show', $request->task_id))
        ->assertSee('Your GitHub account has no write access to geocodio/geocodio.')
        ->assertSee('You are linked as mhansen with read access. Only people with write access can approve.')
        ->assertSee('Check again')
        ->assertSee('Linked as mhansen. Access is checked live and cached for 10 minutes.')
        ->assertDontSee('Approve & open PR')
        ->assertNoJavascriptErrors();
});

it('keeps the feedback draft when approve fails', function () {
    $viewer = User::factory()->withGitHub(4242, 'mhansen')->create();
    $request = heldCoOwnerRequest();
    grantWriteAccess($request, $viewer);
    $this->mock(CoOwnerDecisions::class, fn ($mock) => $mock->shouldReceive('approve')
        ->andThrow(CoOwnerDecisionException::githubFailed(403, 'Resource not accessible by integration')));
    $this->actingAs($viewer);

    visit(route('tasks.show', $request->task_id))
        ->click('[data-testid="co-owner-request-changes"]')
        ->fill('[data-testid="co-owner-feedback-text"]', 'Keep the headers in separate tasks')
        ->click('[data-testid="co-owner-approve"]')
        ->assertSee('Could not open the PR.')
        ->assertSee('GitHub rejected the request. Nothing was merged and the branch is untouched, so it is safe to try again.')
        ->assertSee('403 Resource not accessible by integration')
        ->assertSee('Retry approve')
        ->assertValue('[data-testid="co-owner-feedback-text"]', 'Keep the headers in separate tasks')
        ->assertNoJavascriptErrors();
});

it('shows who Yak tried to ask when nobody resolves', function () {
    $requester = User::factory()->create();
    $request = heldCoOwnerRequest(['ask_attempts' => [['entry' => '@geocodio/platform', 'kind' => 'team', 'outcome' => 'not_linked']]], $requester);
    $this->actingAs($requester);

    visit(route('tasks.show', $request->task_id))
        ->assertSee('Nobody can be asked yet')
        ->assertSee('Yak could not find a co-owner for this change. It stays held until one is available.')
        ->assertSee('Who Yak tried to ask')
        ->assertSee('CODEOWNERS')
        ->assertSee('Fallback owner')
        ->assertSee('Requester')
        ->assertSee('How to fix it')
        ->assertSee("A repository admin can add owners who have linked GitHub to CODEOWNERS, or set a fallback owner in geocodio/geocodio's repository settings in Yak.")
        ->assertSee('Withdraw')
        ->assertSee('Copy link to share')
        ->assertSee('Open repository page')
        ->assertNoJavascriptErrors();
});

it('words the CODEOWNERS read and resolve failures plainly', function () {
    $requester = User::factory()->create();
    $request = heldCoOwnerRequest(['ask_attempts' => [
        ['entry' => 'CODEOWNERS', 'kind' => 'codeowners', 'outcome' => 'error'],
        ['entry' => 'owners', 'kind' => 'codeowners', 'outcome' => 'error'],
    ]], $requester);
    $this->actingAs($requester);

    visit(route('tasks.show', $request->task_id))
        ->assertSee('CODEOWNERS could not be read, so no owner was asked.')
        ->assertSee('Yak could not resolve the owners.')
        ->assertDontSee('CODEOWNERS CODEOWNERS')
        ->assertDontSee('Yak could not check this one.')
        ->assertNoJavascriptErrors();
});

it('shows the expired and withdrawn final states', function () {
    $viewer = User::factory()->create(['name' => 'Dana Whitfield']);
    $this->actingAs($viewer);
    $expired = heldCoOwnerRequest(['status' => CoOwnerRequestStatus::Expired, 'decided_at' => now()]);
    $withdrawn = heldCoOwnerRequest(['status' => CoOwnerRequestStatus::Withdrawn, 'decided_at' => now(), 'decided_by_user_id' => $viewer->id]);

    visit(route('tasks.show', $expired->task_id))
        ->assertSee('This request expired')
        ->assertSee('No co-owner decided within 7 days, so Yak closed the task. No PR was opened.')
        ->assertSee('The branch yak/api-headers is kept. To try again, ask Yak in the original Slack thread or start a new task from this one.')
        ->assertSee('Start a new task from this')
        ->assertNoJavascriptErrors();

    visit(route('tasks.show', $withdrawn->task_id))
        ->assertSee('This request was withdrawn')
        ->assertSee('Dana Whitfield withdrew it on')
        ->assertSee('No PR was opened.')
        ->assertSee('Yak deleted the branch yak/api-headers.')
        ->assertSee('Start a new task from this')
        ->assertNoJavascriptErrors();
});
