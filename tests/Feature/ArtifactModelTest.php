<?php

use App\Models\Artifact;
use App\Models\YakTask;

test('videoCuts scope returns only video_cut artifacts', function () {
    $task = YakTask::factory()->create();
    Artifact::factory()->for($task, 'task')->screenshot()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    $cut = Artifact::factory()->for($task, 'task')->videoCut()->create();

    $results = Artifact::videoCuts()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($cut->id);
});

test('reviewerCut scope returns only reviewer-cut video_cuts', function () {
    $task = YakTask::factory()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'filename' => 'director-cut.mp4',
        'disk_path' => 'director-cut.mp4',
    ]);
    $reviewer = Artifact::factory()->for($task, 'task')->videoCut()->create();

    $results = Artifact::reviewerCut()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($reviewer->id);
});
