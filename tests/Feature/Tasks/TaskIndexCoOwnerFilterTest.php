<?php

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['has_seen_setup_card_at' => now()]));
});

/**
 * A root whose held request sits on a follow-up child, so the root's own
 * status is not awaiting_co_owner.
 */
function heldFollowUpRoot(array $askedUserIds = [], CoOwnerRequestStatus $status = CoOwnerRequestStatus::Pending): YakTask
{
    $root = YakTask::factory()->success()->create(['branch_name' => 'yak/held']);
    $child = YakTask::factory()->awaitingCoOwner()->create(['parent_task_id' => $root->id, 'branch_name' => 'yak/held']);
    CoOwnerRequest::factory()->create([
        'task_id' => $child->id,
        'root_task_id' => $root->id,
        'status' => $status,
        'asked_user_ids' => $askedUserIds,
    ]);

    return $root;
}

it('filters tasks that need a co-owner', function () {
    $held = heldFollowUpRoot();
    YakTask::factory()->success()->create();
    heldFollowUpRoot(status: CoOwnerRequestStatus::Approved);

    $this->get(route('tasks', ['status' => 'awaiting_co_owner']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1)
            ->where('tasks.data.0.id', $held->id));
});

it('counts held tasks for the chip', function () {
    heldFollowUpRoot();
    heldFollowUpRoot();
    YakTask::factory()->success()->create();
    heldFollowUpRoot(status: CoOwnerRequestStatus::Expired);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->where('counts.needsCoOwner', 2));
});

it('names the first person asked on the held row', function () {
    $mathias = User::factory()->create(['name' => 'Mathias Hansen']);
    $cory = User::factory()->create(['name' => 'Cory Mawhorter']);
    $held = heldFollowUpRoot([$mathias->id, $cory->id]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.data.0.id', $held->id)
            ->where('tasks.data.0.askedFirstName', 'Mathias'));
});
