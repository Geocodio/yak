<?php

use App\Channels\Linear\SessionPlanStage;
use App\Enums\CoOwnerRequestStatus;
use App\Http\Resources\TaskDetailData;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Request;

it('returns the latest pending request as the open one', function () {
    $task = YakTask::factory()->awaitingCoOwner()->create();
    CoOwnerRequest::factory()->for($task, 'task')->create(['status' => CoOwnerRequestStatus::Approved, 'held_at' => now()->subDay()]);
    $pending = CoOwnerRequest::factory()->for($task, 'task')->create(['status' => CoOwnerRequestStatus::Pending]);

    expect($task->openCoOwnerRequest()?->id)->toBe($pending->id)
        ->and($task->coOwnerRequests)->toHaveCount(2);
});

it('casts matched areas and changed files to arrays', function () {
    $request = CoOwnerRequest::factory()->create()->fresh();

    expect($request->matched_areas[0]['risk'])->toBe('high')
        ->and($request->changed_files)->toHaveCount(2)
        ->and($request->status)->toBe(CoOwnerRequestStatus::Pending)
        ->and($request->held_at)->toBeInstanceOf(DateTimeImmutable::class);
});

it('knows which asked users it holds and which statuses are resolved', function () {
    $user = User::factory()->withGitHub()->create();
    $request = CoOwnerRequest::factory()->create(['asked_user_ids' => [$user->id]]);

    expect($request->askedUsers()->pluck('id')->all())->toBe([$user->id])
        ->and($user->hasLinkedGitHub())->toBeTrue()
        ->and(User::factory()->create()->hasLinkedGitHub())->toBeFalse()
        ->and(CoOwnerRequestStatus::Pending->isResolved())->toBeFalse()
        ->and(CoOwnerRequestStatus::Superseded->isResolved())->toBeTrue();
});

it('renders a held task detail page and a session plan stage', function () {
    $task = YakTask::factory()->awaitingCoOwner()->create();

    $props = TaskDetailData::build($task, Request::create('/'));

    expect($props['task']['status'])->toBe('awaiting_co_owner')
        ->and(SessionPlanStage::forTask($task))->toBe(SessionPlanStage::AwaitingCi);

    $this->withoutVite()->actingAs(User::factory()->create())->get(route('tasks.show', $task))->assertOk();
});
