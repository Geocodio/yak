<?php

use App\Enums\ThreadListFilter;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadList;

it('lists children under their parent and hides private children from others', function () {
    $viewer = User::factory()->create();
    $owner = User::factory()->create();

    $team = Thread::factory()->create(['title' => 'Team root', 'created_by_user_id' => $viewer->id]);
    $teamChild = Thread::factory()->child($team)->create(['title' => 'Team child']);
    $private = Thread::factory()->private($owner)->create(['title' => 'Private root']);
    Thread::factory()->child($private)->create(['title' => 'Private child']);

    $asViewer = collect(ThreadList::for($viewer, ThreadListFilter::Everyone)['threads'])->concat(ThreadList::for($viewer, ThreadListFilter::Everyone)['needsYou']);
    $row = $asViewer->firstWhere('id', $team->public_id);

    expect(collect($row['children'])->pluck('id')->all())->toBe([$teamChild->public_id])
        ->and($asViewer->pluck('title')->all())->not->toContain('Private root');

    $asOwner = collect(ThreadList::for($owner, ThreadListFilter::Everyone)['threads'])->concat(ThreadList::for($owner, ThreadListFilter::Everyone)['needsYou'])->firstWhere('id', $private->public_id);

    expect(collect($asOwner['children'])->pluck('title')->all())->toBe(['Private child']);
});
