<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;

it('stores a queued result and casts kind', function () {
    $message = StackThreadMessage::factory()->queued()->create(['kind' => StackThreadMessageKind::Result]);

    expect($message->fresh()->kind)->toBe(StackThreadMessageKind::Result)
        ->and($message->queued_at)->not->toBeNull()
        ->and($message->delivered_at)->toBeNull()
        ->and(StackThreadMessage::factory()->delivered()->create()->delivered_at)->not->toBeNull()
        ->and($message->from->is(Thread::withoutGlobalScopes()->find($message->from_thread_id)))->toBeTrue();
});

it('cascades when the root thread is deleted', function () {
    $root = Thread::factory()->create();
    $child = Thread::factory()->child($root)->create();
    StackThreadMessage::factory()->create(['root_thread_id' => $root->id, 'from_thread_id' => $child->id, 'to_thread_id' => $root->id]);

    $root->delete();

    expect(StackThreadMessage::count())->toBe(0);
});

it('identifies research children sharing the parent sandbox', function () {
    $parent = Thread::factory()->create(['sandbox_container' => 'yak-thread-1']);
    $research = Thread::factory()->child($parent)->create(['kind' => ThreadKind::Research, 'sandbox_container' => 'yak-thread-1']);
    $implement = Thread::factory()->child($parent)->create(['sandbox_container' => 'yak-thread-2']);
    $ownResearch = Thread::factory()->child($parent)->create(['kind' => ThreadKind::Research, 'sandbox_container' => 'yak-thread-3']);

    expect($research->isResearchChild())->toBeTrue()
        ->and($research->isChild())->toBeTrue()
        ->and($research->sharesParentSandbox())->toBeTrue()
        ->and($implement->isResearchChild())->toBeFalse()
        ->and($implement->sharesParentSandbox())->toBeFalse()
        ->and($ownResearch->sharesParentSandbox())->toBeFalse()
        ->and($parent->isChild())->toBeFalse()
        ->and($parent->sharesParentSandbox())->toBeFalse();
});
