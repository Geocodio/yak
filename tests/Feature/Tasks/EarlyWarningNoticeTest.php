<?php

use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

function grantAccess(Repository $repository, User $user, string $access = 'write'): void
{
    Cache::put("co-owner:write-access:{$repository->id}:{$user->github_id}", $access, now()->addMinutes(10));
}

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r']);
    $this->requester = User::factory()->create(['name' => 'Rita Requester']);
    $this->task = YakTask::factory()->create([
        'repo' => 'o-r',
        'started_by_user_id' => $this->requester->id,
        'early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown'],
    ]);
});

function writeAccessCrowd(object $test): User
{
    $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
    $reader = User::factory()->withGitHub(2, 'b')->create(['name' => 'Rex Reader']);
    User::factory()->create(['name' => 'Unlinked Una']);
    grantAccess($test->repository, $writer);
    grantAccess($test->repository, $reader, 'read');

    return $writer;
}

it('shows the notice and deferred candidates to the requester', function () {
    $writer = writeAccessCrowd($this);

    $this->actingAs($this->requester)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('earlyWarning.state', 'shown')
            ->where('earlyWarning.canPick', true)
            ->where('earlyWarning.repoName', 'o/r')
            ->where('earlyWarning.areas.0.name', 'Billing')
            ->missing('earlyWarningCandidates')
            ->loadDeferredProps('early-warning', fn (Assert $deferred) => $deferred
                ->has('earlyWarningCandidates', 1)
                ->where('earlyWarningCandidates.0', ['id' => $writer->id, 'name' => 'Wanda Writer'])));
});

it('gives a non-requester the notice without the picker or candidates', function () {
    $writer = writeAccessCrowd($this);

    $response = $this->actingAs($writer)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('earlyWarning.canPick', false)
            ->missing('earlyWarningCandidates'));

    expect($response->viewData('page')['deferredProps'] ?? [])->not->toHaveKey('early-warning');
});

it('hides the notice without a warning', function () {
    $other = YakTask::factory()->create(['repo' => 'o-r', 'started_by_user_id' => $this->requester->id]);
    $this->actingAs($this->requester)->get(route('tasks.show', $other))
        ->assertInertia(fn (Assert $page) => $page->where('earlyWarning', null));
});

it('hides the notice once a co-owner request exists', function () {
    CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id]);
    $this->actingAs($this->requester)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page->where('earlyWarning', null));
});

it('reads a deleted tagged user as untagged', function () {
    $this->task->update(['early_warning' => [...$this->task->early_warning, 'state' => 'tagged'], 'tagged_co_owner_user_id' => null]);

    $this->actingAs($this->requester)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page->where('earlyWarning.state', 'shown')->where('earlyWarning.taggedName', null));
});

it('tags a co-owner with write access', function () {
    $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
    grantAccess($this->repository, $writer);

    $this->actingAs($this->requester)->post(route('tasks.co-owner.tag', $this->task), ['user_id' => $writer->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($this->task->fresh())->tagged_co_owner_user_id->toBe($writer->id)->early_warning->toMatchArray(['state' => 'tagged']);
});

it('shows the tagged name afterwards', function () {
    $writer = User::factory()->withGitHub(1, 'a')->create(['name' => 'Wanda Writer']);
    $this->task->update(['early_warning' => [...$this->task->early_warning, 'state' => 'tagged'], 'tagged_co_owner_user_id' => $writer->id]);

    $this->actingAs($this->requester)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('earlyWarning.state', 'tagged')
            ->where('earlyWarning.taggedName', 'Wanda Writer')
            ->where('earlyWarning.canPick', false));
});

it('refuses a tag without write access and from a non-requester', function () {
    $reader = User::factory()->withGitHub(2, 'b')->create();
    grantAccess($this->repository, $reader, 'read');

    $this->actingAs($this->requester)->post(route('tasks.co-owner.tag', $this->task), ['user_id' => $reader->id])
        ->assertSessionHasErrors('earlyWarning');
    expect(session('errors')->get('earlyWarning')[0]['reason'])->toBe('no_write_access');

    $writer = User::factory()->withGitHub(1, 'a')->create();
    grantAccess($this->repository, $writer);
    $this->actingAs($writer)->post(route('tasks.co-owner.tag', $this->task), ['user_id' => $writer->id])
        ->assertSessionHasErrors('earlyWarning');
    expect(session('errors')->get('earlyWarning')[0]['reason'])->toBe('not_requester');
    expect($this->task->fresh()->tagged_co_owner_user_id)->toBeNull();
});

it('declines for the requester only', function () {
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->post(route('tasks.co-owner.decline', $this->task))->assertSessionHasErrors('earlyWarning');
    expect($this->task->fresh()->early_warning['state'])->toBe('shown');

    $this->actingAs($this->requester)->post(route('tasks.co-owner.decline', $this->task))->assertSessionHasNoErrors();
    expect($this->task->fresh()->early_warning['state'])->toBe('declined');
});
