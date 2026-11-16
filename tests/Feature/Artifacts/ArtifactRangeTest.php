<?php

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->task = YakTask::factory()->create();
    $this->artifact = Artifact::factory()->video()->create([
        'yak_task_id' => $this->task->id,
        'filename' => 'walkthrough.mp4',
        'disk_path' => 'walkthrough.mp4',
    ]);
    Storage::disk('artifacts')->put('walkthrough.mp4', '0123456789abcdef');
});

function signedPathOf(string $url): string
{
    return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
}

it('serves a byte range of a signed walkthrough video without a session', function () {
    $response = $this->withHeader('Range', 'bytes=0-1')
        ->get(signedPathOf($this->artifact->signedUrl()));

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-1/16')
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', 'video/mp4');

    ob_start();
    $response->baseResponse->sendContent();

    expect(ob_get_clean())->toBe('01');
});

it('answers 403 for an expired signature', function () {
    $url = URL::temporarySignedRoute('artifacts.show', now()->subDay(), [
        'task' => $this->task->public_id,
        'filename' => 'walkthrough.mp4',
    ]);

    $response = $this->withHeader('Range', 'bytes=0-1')->get(signedPathOf($url));

    $response->assertStatus(403);
    expect($response->headers->has('Content-Range'))->toBeFalse();
});

it('answers 416 with the file size and not the file for a range past the end', function () {
    $response = $this->withHeader('Range', 'bytes=100-')
        ->get(signedPathOf($this->artifact->signedUrl()));

    $response->assertStatus(416)->assertHeader('Content-Range', 'bytes */16');

    ob_start();
    $response->baseResponse->sendContent();

    expect(ob_get_clean())->not->toBe('0123456789abcdef');
});

it('answers 200 with the whole file when there is no Range header', function () {
    $response = $this->get(signedPathOf($this->artifact->signedUrl()));

    $response->assertStatus(200);

    ob_start();
    $response->baseResponse->sendContent();

    expect(ob_get_clean())->toBe('0123456789abcdef');
});

it('answers 206 with one byte for an open-ended range at the last byte', function () {
    $response = $this->withHeader('Range', 'bytes=15-')
        ->get(signedPathOf($this->artifact->signedUrl()));

    $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 15-15/16');
});

it('answers 200 with the whole file when If-Range does not match', function () {
    $this->withHeaders(['Range' => 'bytes=100-', 'If-Range' => '"stale-etag"'])
        ->get(signedPathOf($this->artifact->signedUrl()))
        ->assertStatus(200);
});

it('ignores a reversed range and answers 200', function () {
    $this->withHeader('Range', 'bytes=20-5')
        ->get(signedPathOf($this->artifact->signedUrl()))
        ->assertStatus(200);
});

it('does not answer 416 for a HEAD request', function () {
    $this->withHeader('Range', 'bytes=100-')
        ->call('HEAD', signedPathOf($this->artifact->signedUrl()), [], [], [], ['HTTP_RANGE' => 'bytes=100-'])
        ->assertStatus(200);
});
