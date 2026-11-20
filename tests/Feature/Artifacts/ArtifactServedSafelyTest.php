<?php

use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->actingAs(User::factory()->create());
    $this->task = YakTask::factory()->create();
});

function openArtifact(string $filename, string $contents)
{
    $artifact = Artifact::factory()->create([
        'yak_task_id' => test()->task->id,
        'filename' => $filename,
        'disk_path' => $filename,
    ]);
    Storage::disk('artifacts')->put($filename, $contents);

    return test()->get(route('artifacts.show', ['task' => test()->task->public_id, 'filename' => $filename]));
}

it('serves html in a script-only sandbox with nosniff', function () {
    $response = openArtifact('report.html', '<script>1</script>')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe('sandbox allow-scripts')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('never serves svg or other runnable types as an inline image', function (string $filename) {
    $response = openArtifact($filename, '<svg xmlns="http://www.w3.org/2000/svg"/>')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->with(['image.svg', 'page.xhtml', 'data.xml', 'app.js', 'thing.unknown']);

it('serves a png inline as an image', function () {
    $response = openArtifact('shot.png', 'png-bytes')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('serves a walkthrough video inline', function () {
    openArtifact('walk.mp4', '0123')->assertOk()->assertHeader('Content-Type', 'video/mp4');
});

it('frames the artifact from the viewer', function () {
    $artifact = Artifact::factory()->research()->create(['yak_task_id' => $this->task->id]);

    $this->get(route('artifacts.viewer', ['task' => $this->task->public_id, 'filename' => $artifact->filename]))
        ->assertOk()
        ->assertSee('data-testid="artifact-iframe"', false)
        ->assertSee(route('artifacts.show', ['task' => $this->task->public_id, 'filename' => $artifact->filename]), false);
});
