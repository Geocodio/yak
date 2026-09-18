<?php

use App\Enums\TaskMode;
use App\Models\YakTask;

test('prIsOpen reflects PR lifecycle', function () {
    expect(YakTask::factory()->success()->create()->prIsOpen())->toBeTrue()
        ->and(YakTask::factory()->merged()->create()->prIsOpen())->toBeFalse()
        ->and(YakTask::factory()->closedWithoutMerge()->create()->prIsOpen())->toBeFalse()
        ->and(YakTask::factory()->pending()->create(['pr_url' => null])->prIsOpen())->toBeFalse();
});

test('prState reflects PR lifecycle', function () {
    expect(YakTask::factory()->success()->create()->prState())->toBe('open')
        ->and(YakTask::factory()->merged()->create()->prState())->toBe('merged')
        ->and(YakTask::factory()->closedWithoutMerge()->create()->prState())->toBe('closed')
        ->and(YakTask::factory()->pending()->create(['pr_url' => null])->prState())->toBeNull();
});

test('conversation returns the full chain ordered by created_at', function () {
    $root = YakTask::factory()->success()->create(['branch_name' => 'yak/CHAIN-1']);
    $child = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'branch_name' => 'yak/CHAIN-1',
        'created_at' => now()->addMinute(),
    ]);
    $grandchild = YakTask::factory()->create([
        'parent_task_id' => $child->id,
        'branch_name' => 'yak/CHAIN-1',
        'created_at' => now()->addMinutes(2),
    ]);

    $ids = $child->conversation()->pluck('id')->all();

    expect($ids)->toBe([$root->id, $child->id, $grandchild->id])
        ->and($root->followUps()->pluck('id')->all())->toBe([$child->id])
        ->and($child->parent->id)->toBe($root->id);
});

test('conversation on a lone task returns just itself', function () {
    $solo = YakTask::factory()->success()->create(['branch_name' => 'yak/SOLO-1']);

    expect($solo->conversation()->pluck('id')->all())->toBe([$solo->id]);
});

test('followUpRootForPr prefers the parentless root over a later non-root task', function () {
    $root = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/ROOT-1',
    ]);
    YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'parent_task_id' => $root->id,
        'branch_name' => 'yak/ROOT-1',
        'created_at' => now()->addMinute(),
    ]);

    expect(YakTask::followUpRootForPr('https://github.com/acme/web/pull/9')->id)->toBe($root->id);
});

test('followUpRootForPr falls back to the oldest non-Review task when no root matches', function () {
    $otherRoot = YakTask::factory()->success()->create(['branch_name' => 'yak/OTHER-1']);
    $child = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'parent_task_id' => $otherRoot->id,
        'branch_name' => 'yak/CHAIN-1',
    ]);

    expect(YakTask::followUpRootForPr('https://github.com/acme/web/pull/9')->id)->toBe($child->id);
});

test('followUpRootForPr excludes Review-mode tasks and returns null when none remain', function () {
    YakTask::factory()->success()->create([
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/x',
    ]);

    expect(YakTask::followUpRootForPr('https://github.com/acme/web/pull/9'))->toBeNull();
});
