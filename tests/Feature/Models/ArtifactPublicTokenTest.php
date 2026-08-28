<?php

use App\Models\Artifact;
use App\Models\YakTask;

it('generates a public token for preview and thumbnail artifacts', function (string $role): void {
    $artifact = Artifact::create([
        'yak_task_id' => YakTask::factory()->create()->id,
        'type' => 'video_thumbnail',
        'role' => $role,
        'filename' => 'x.jpg',
        'disk_path' => '1/x.jpg',
        'size_bytes' => 1,
    ]);

    expect($artifact->public_token)->toBeString()->toHaveLength(26)
        ->and($artifact->publicUrl())->toBe(route('artifacts.public', ['token' => $artifact->public_token]));
})->with(['preview', 'thumbnail']);

it('leaves other roles without a public token', function (): void {
    $artifact = Artifact::create([
        'yak_task_id' => YakTask::factory()->create()->id,
        'type' => 'video_cut',
        'role' => 'cut',
        'filename' => 'walkthrough.mp4',
        'disk_path' => '1/walkthrough.mp4',
        'size_bytes' => 1,
    ]);

    expect($artifact->public_token)->toBeNull()
        ->and($artifact->publicUrl())->toBeNull();
});

it('stores a caption', function (): void {
    $artifact = Artifact::create([
        'yak_task_id' => YakTask::factory()->create()->id,
        'type' => 'screenshot',
        'role' => 'screenshot',
        'filename' => 'zip.png',
        'disk_path' => '1/screenshots/zip.png',
        'size_bytes' => 1,
        'caption' => 'New ZIP-level section with the no-ZCTA warning',
    ]);

    expect($artifact->fresh()->caption)->toBe('New ZIP-level section with the no-ZCTA warning');
});
