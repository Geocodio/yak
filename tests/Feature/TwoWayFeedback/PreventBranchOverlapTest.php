<?php

use App\Jobs\ClarificationReplyJob;
use App\Jobs\Middleware\PreventBranchOverlap;
use App\Jobs\RetryYakJob;
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

test('PreventBranchOverlap falls back to task id when branch is null and configures TTLs', function () {
    $task = YakTask::factory()->create(['repo' => 'web', 'branch_name' => null]);
    $mw = new PreventBranchOverlap($task);

    expect($mw->key)->toBe('web:task-' . $task->id)
        ->and($mw->releaseAfter)->toBe(30)
        ->and($mw->expiresAfter)->toBe(4200);
});

test('ClarificationReplyJob is guarded by PreventBranchOverlap', function () {
    $task = YakTask::factory()->make(['repo' => 'web', 'branch_name' => 'yak/CSV-1']);
    $classes = array_map(fn ($m) => $m::class, (new ClarificationReplyJob($task, 'go with option 1'))->middleware());

    expect($classes)->toContain(PreventBranchOverlap::class);
});

test('RetryYakJob is guarded by PreventBranchOverlap', function () {
    $task = YakTask::factory()->make(['repo' => 'web', 'branch_name' => 'yak/CSV-1']);
    $classes = array_map(fn ($m) => $m::class, (new RetryYakJob($task))->middleware());

    expect($classes)->toContain(PreventBranchOverlap::class);
});

test('ClarificationReplyJob PreventBranchOverlap keys on the branch name', function () {
    $task = YakTask::factory()->make(['repo' => 'acme/app', 'branch_name' => 'yak/ISSUE-42']);
    $middleware = (new ClarificationReplyJob($task, 'use approach A'))->middleware();
    $overlap = collect($middleware)->first(fn ($m) => $m instanceof PreventBranchOverlap);

    expect($overlap)->not->toBeNull()
        ->and($overlap->key)->toBe('acme/app:yak/ISSUE-42');
});

test('RetryYakJob PreventBranchOverlap keys on the branch name', function () {
    $task = YakTask::factory()->make(['repo' => 'acme/app', 'branch_name' => 'yak/ISSUE-42']);
    $middleware = (new RetryYakJob($task))->middleware();
    $overlap = collect($middleware)->first(fn ($m) => $m instanceof PreventBranchOverlap);

    expect($overlap)->not->toBeNull()
        ->and($overlap->key)->toBe('acme/app:yak/ISSUE-42');
});
