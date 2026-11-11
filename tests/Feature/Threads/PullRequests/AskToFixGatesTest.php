<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadVisibility;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

function askToFixRow(array $thread = [], string $ciStatus = 'failing'): array
{
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web']);
    $owner = User::factory()->create();
    $thread = Thread::factory()->create($thread + ['owner_user_id' => $owner->id, 'visibility' => ThreadVisibility::Team]);
    $row = ThreadRepository::factory()->for($thread)->changes()->withPullRequest(188)->create(['repo' => 'acme/web', 'ci_status' => $ciStatus]);

    return [$thread, $owner, $row];
}

it('refuses with 409 while the device owns the thread', function () {
    Queue::fake();
    [$thread, $owner, $row] = askToFixRow(['owner_side' => ThreadOwnerSide::Device]);

    $this->actingAs($owner)->postJson("/threads/{$thread->id}/pull-requests/{$row->id}/fix")->assertStatus(409);

    Queue::assertNothingPushed();
});

it('refuses with 409 on a closed thread', function () {
    Queue::fake();
    [$thread, $owner, $row] = askToFixRow();
    $thread->forceFill(Thread::factory()->closed()->make()->only(['status', 'closed_at', 'close_reason']))->save();

    $this->actingAs($owner)->postJson("/threads/{$thread->id}/pull-requests/{$row->id}/fix")->assertStatus(409);

    Queue::assertNothingPushed();
});

it('refuses with 409 when CI is not failing', function (?string $ciStatus) {
    Queue::fake();
    [$thread, $owner, $row] = askToFixRow([], 'failing');
    $row->forceFill(['ci_status' => $ciStatus])->save();

    $this->actingAs($owner)->postJson("/threads/{$thread->id}/pull-requests/{$row->id}/fix")->assertStatus(409);

    Queue::assertNothingPushed();
})->with(['passing', null]);

it('accepts a failing row on an open Yak-owned thread', function () {
    Queue::fake();
    [$thread, $owner, $row] = askToFixRow();

    $this->actingAs($owner)->postJson("/threads/{$thread->id}/pull-requests/{$row->id}/fix")->assertStatus(202)->assertJson(['ok' => true]);

    Queue::assertPushed(RetryRepositoryCiJob::class, 1);
});

it('keeps a retry unique per repository row', function () {
    $job = new RetryRepositoryCiJob(5);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('5')
        ->and($job->uniqueFor())->toBe(21600)
        ->and((new RetryRepositoryCiJob(6))->uniqueId())->not->toBe($job->uniqueId());
});
