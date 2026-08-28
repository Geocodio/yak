<?php

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

function makePublicArtifact(string $role, string $filename = 'walkthrough-preview.gif'): Artifact
{
    $task = YakTask::factory()->create();
    Storage::disk('artifacts')->put("{$task->id}/{$filename}", 'GIF89a-bytes');

    return Artifact::create([
        'yak_task_id' => $task->id,
        'type' => 'screenshot',
        'role' => $role,
        'filename' => $filename,
        'disk_path' => "{$task->id}/{$filename}",
        'size_bytes' => 12,
    ]);
}

it('serves a preview gif by token with a long cache header', function (): void {
    Storage::fake('artifacts');
    $artifact = makePublicArtifact('preview');

    $this->get($artifact->publicUrl())
        ->assertOk()
        ->assertHeader('Content-Type', 'image/gif')
        ->assertHeader('Cache-Control', 'max-age=31536000, public');
});

it('serves a thumbnail by token', function (): void {
    Storage::fake('artifacts');
    $artifact = makePublicArtifact('thumbnail', 'walkthrough-thumbnail.jpg');

    $this->get($artifact->publicUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
});

it('404s a token whose row is not a public role', function (): void {
    Storage::fake('artifacts');
    $artifact = makePublicArtifact('preview');
    $artifact->update(['role' => 'cut']);

    $this->get(route('artifacts.public', ['token' => $artifact->public_token]))->assertNotFound();
});

it('404s an unknown token', function (): void {
    $this->get(route('artifacts.public', ['token' => str_repeat('a', 26)]))->assertNotFound();
});

it('404s when the file is gone', function (): void {
    Storage::fake('artifacts');
    $artifact = makePublicArtifact('preview');
    Storage::disk('artifacts')->delete($artifact->disk_path);

    $this->get($artifact->publicUrl())->assertNotFound();
});
