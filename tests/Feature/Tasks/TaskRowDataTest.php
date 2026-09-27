<?php

use App\Http\Resources\TaskRowData;
use App\Models\YakTask;

test('the description preview is plain text without markdown syntax', function () {
    $task = YakTask::factory()->create([
        'description' => "API keys skip instance assignment\n\n## Bottom line\n\n**Goal:** keep `owner` keys & team keys together",
    ]);

    expect(TaskRowData::from($task)['description'])
        ->toBe('API keys skip instance assignment Bottom line Goal: keep owner keys & team keys together');
});

test('the description preview is capped at 140 characters', function () {
    $task = YakTask::factory()->create(['description' => str_repeat('word ', 100)]);

    expect(mb_strlen(TaskRowData::from($task)['description']))->toBeLessThanOrEqual(143);
});
