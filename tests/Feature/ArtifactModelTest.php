<?php

use App\Models\Artifact;
use App\Models\YakTask;

test('cut scope returns artifacts with the cut role regardless of filename', function () {
    $task = YakTask::factory()->create();
    Artifact::factory()->for($task, 'task')->screenshot()->create();
    Artifact::factory()->for($task, 'task')->video()->create();
    $legacy = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'role' => 'cut',
        'filename' => 'reviewer-cut.mp4',
        'disk_path' => 'reviewer-cut.mp4',
    ]);
    $current = Artifact::factory()->for($task, 'task')->videoCut()->create();

    expect(Artifact::cut()->pluck('id')->all())
        ->toEqualCanonicalizing([$legacy->id, $current->id]);
});

test('thumbnail scope matches old and new thumbnail filenames', function () {
    $task = YakTask::factory()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();
    $legacy = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_thumbnail',
        'role' => 'thumbnail',
        'filename' => 'reviewer-cut-thumbnail.jpg',
        'disk_path' => 'reviewer-cut-thumbnail.jpg',
    ]);
    $current = Artifact::factory()->for($task, 'task')->videoThumbnail()->create();

    expect(Artifact::thumbnail()->pluck('id')->all())
        ->toEqualCanonicalizing([$legacy->id, $current->id]);
});

test('rawFootage scope returns raw recordings only', function () {
    $task = YakTask::factory()->create();
    Artifact::factory()->for($task, 'task')->videoCut()->create();
    $raw = Artifact::factory()->for($task, 'task')->video()->create();

    expect(Artifact::rawFootage()->pluck('id')->all())->toBe([$raw->id]);
});

test('role scope takes an arbitrary role', function () {
    $task = YakTask::factory()->create();
    $manifest = Artifact::factory()->for($task, 'task')->create([
        'type' => 'file',
        'role' => 'manifest',
        'filename' => 'storyboard.json',
        'disk_path' => 'storyboard.json',
    ]);
    Artifact::factory()->for($task, 'task')->videoCut()->create();

    expect(Artifact::role('manifest')->pluck('id')->all())->toBe([$manifest->id]);
});

test('a director cut artifact never surfaces as the walkthrough', function () {
    $task = YakTask::factory()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_cut',
        'role' => null,
        'filename' => 'director-cut.mp4',
        'disk_path' => 'director-cut.mp4',
    ]);

    expect(Artifact::cut()->count())->toBe(0);
});
