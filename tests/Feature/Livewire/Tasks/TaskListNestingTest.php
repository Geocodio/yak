<?php

use App\Enums\TaskStatus;
use App\Livewire\Tasks\TaskList;
use App\Models\YakTask;
use Livewire\Livewire;

test('a root task with follow-ups nests its children and is the only top-level row', function () {
    $root = YakTask::factory()->success()->create([
        'repo' => 'web',
        'external_id' => 'ROOT-1',
        'branch_name' => 'yak/R-1',
    ]);
    $childA = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'repo' => 'web',
        'external_id' => 'ROOT-1-followup-1',
        'branch_name' => 'yak/R-1',
        'description' => 'first follow up change',
    ]);
    $childB = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'repo' => 'web',
        'external_id' => 'ROOT-1-followup-2',
        'branch_name' => 'yak/R-1',
        'description' => 'second follow up change',
    ]);

    Livewire::test(TaskList::class)
        ->assertSee('ROOT-1')
        ->assertSee('ROOT-1-followup-1')   // child rendered nested
        ->assertSee('ROOT-1-followup-2')
        ->assertSee('2 follow-ups');        // badge on the root

    // The computed query returns only the root at the top level (children are not separate paginated rows)
    $topLevelIds = Livewire::test(TaskList::class)->instance()->tasks->pluck('id')->all();
    expect($topLevelIds)->toContain($root->id)
        ->and($topLevelIds)->not->toContain($childA->id)
        ->and($topLevelIds)->not->toContain($childB->id);
});

test('a standalone task renders normally with no follow-ups badge', function () {
    YakTask::factory()->success()->create(['external_id' => 'SOLO-1', 'repo' => 'web']);

    Livewire::test(TaskList::class)
        ->assertSee('SOLO-1')
        ->assertDontSee('follow-ups');
});

test('top-level list excludes follow-up children even across tabs/filters', function () {
    $root = YakTask::factory()->success()->create(['repo' => 'web', 'external_id' => 'FR-1', 'branch_name' => 'yak/F-1']);
    YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'repo' => 'web',
        'external_id' => 'FR-1-followup-1',
        'branch_name' => 'yak/F-1',
        'status' => TaskStatus::Running,
    ]);

    // Even filtering by a status the child has, children never appear as their own top-level row.
    $ids = Livewire::test(TaskList::class)->set('status', 'running')->instance()->tasks->pluck('id')->all();
    expect($ids)->not->toContain($root->followUps()->first()->id);
});

test('a multi-level follow-up chain shows grandchildren nested under the root', function () {
    $root = YakTask::factory()->success()->create(['repo' => 'web', 'external_id' => 'GC-1', 'branch_name' => 'yak/GC-1']);
    $child = YakTask::factory()->create([
        'parent_task_id' => $root->id, 'repo' => 'web',
        'external_id' => 'GC-1-followup-1', 'branch_name' => 'yak/GC-1',
    ]);
    $grandchild = YakTask::factory()->create([
        'parent_task_id' => $child->id, 'repo' => 'web',  // parent is the CHILD, not the root
        'external_id' => 'GC-1-followup-2', 'branch_name' => 'yak/GC-1',
    ]);

    Livewire::test(TaskList::class)
        ->assertSee('GC-1-followup-1')
        ->assertSee('GC-1-followup-2')   // grandchild must still render
        ->assertSee('2 follow-ups');     // count includes the whole chain

    $topLevel = Livewire::test(TaskList::class)->instance()->tasks->pluck('id')->all();
    expect($topLevel)->toContain($root->id)
        ->and($topLevel)->not->toContain($child->id)
        ->and($topLevel)->not->toContain($grandchild->id);
});

test('tab count excludes follow-up children', function () {
    $root = YakTask::factory()->success()->create(['repo' => 'web', 'external_id' => 'TC-1', 'branch_name' => 'yak/TC-1']);
    YakTask::factory()->create(['parent_task_id' => $root->id, 'repo' => 'web', 'external_id' => 'TC-1-followup-1', 'branch_name' => 'yak/TC-1']);

    // Only the root counts toward the Tasks tab badge.
    expect(Livewire::test(TaskList::class)->instance()->tasksCount)->toBe(1);
});
