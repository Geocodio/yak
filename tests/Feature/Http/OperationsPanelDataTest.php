<?php

use App\Enums\TurnState;
use App\Http\Resources\OperationsPanelData;
use App\Models\BranchDeployment;
use App\Models\Observation;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

function runningOperationsThread(User $owner, string $repo, bool $private = false): Thread
{
    $thread = ($private ? Thread::factory()->private($owner) : Thread::factory())
        ->create(['created_by_user_id' => $owner->id, 'repo' => $repo]);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $thread->tasks()->first()?->id ?? YakTask::factory()->create(['thread_id' => $thread->id])->id, 'turn_state' => TurnState::Running]);

    return $thread;
}

test('the operations panel is shared on operations pages and null elsewhere', function () {
    $user = User::factory()->create(['has_seen_setup_card_at' => now()]);

    $this->actingAs($user)->get(route('repos'))
        ->assertInertia(fn (Assert $page) => $page->has('operationsPanel.counts'));
    $this->actingAs($user)->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->where('operationsPanel', null));
});

test('counts reflect deployments, pr reviews and observations', function () {
    $user = User::factory()->create();
    Repository::factory()->count(2)->create();
    BranchDeployment::factory()->running()->count(3)->create();
    BranchDeployment::factory()->destroyed()->create();
    PrReview::factory()->count(2)->create();
    PrReview::factory()->dismissed()->create();
    Observation::factory()->count(2)->create();
    Observation::factory()->create(['created_at' => now()->subDays(3)]);

    $data = OperationsPanelData::for($user);

    expect($data['counts'])->toBe(['repositories' => Repository::count(), 'deployments' => 3, 'prReviews' => 3, 'observations' => 3])
        ->and($data['deploymentsRunning'])->toBe(3)
        ->and($data['prReviewsOpen'])->toBe(2)
        ->and($data['observationsNew'])->toBe(2);
});

test('repository status strings come from the viewer visible threads', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/busy', 'name' => 'Busy Repo']);
    Repository::factory()->create(['slug' => 'acme/quiet', 'name' => 'Quiet Repo']);
    runningOperationsThread($user, 'acme/busy');
    runningOperationsThread($user, 'acme/busy');
    runningOperationsThread($other, 'acme/quiet', private: true);

    $repositories = collect(OperationsPanelData::for($user)['repositories'])->keyBy('slug');

    expect($repositories['acme/busy']['status'])->toBe('2 threads running')
        ->and($repositories['acme/busy']['initials'])->toBe('BR')
        ->and($repositories['acme/quiet']['status'])->toBe('Quiet');

    runningOperationsThread($user, 'acme/quiet', private: true);

    expect(collect(OperationsPanelData::for($user)['repositories'])->keyBy('slug')['acme/quiet']['status'])->toBe('1 thread running');
});
