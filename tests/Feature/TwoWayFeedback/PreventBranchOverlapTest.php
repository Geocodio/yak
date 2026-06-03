<?php

use App\Jobs\Middleware\PreventBranchOverlap;
use App\Jobs\RunFollowUpJob;
use App\Models\YakTask;
use Illuminate\Queue\Middleware\WithoutOverlapping;

test('PreventBranchOverlap keys on repo and branch', function () {
    $task = YakTask::factory()->make(['repo' => 'web', 'branch_name' => 'yak/CSV-1']);
    $mw = new PreventBranchOverlap($task);

    expect($mw)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($mw->key)->toBe('web:yak/CSV-1');
});

test('RunFollowUpJob is guarded by PreventBranchOverlap', function () {
    $task = YakTask::factory()->make(['repo' => 'web', 'branch_name' => 'yak/CSV-1']);
    $classes = array_map(fn ($m) => $m::class, (new RunFollowUpJob($task))->middleware());

    expect($classes)->toContain(PreventBranchOverlap::class);
});
