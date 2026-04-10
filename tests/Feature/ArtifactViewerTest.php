<?php

use App\Models\Artifact;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

// ---- Signed URL Generation ----

test('signedUrl generates a temporary signed route', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    $url = $artifact->signedUrl();

    expect($url)
        ->toContain('/artifacts/'.$task->id.'/'.$artifact->filename)
        ->toContain('signature=')
        ->toContain('expires=');
});

test('signedUrl uses default 7-day expiry', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    $url = $artifact->signedUrl();

    $parsed = parse_url($url);
    parse_str($parsed['query'] ?? '', $query);

    $expires = (int) $query['expires'];
    $expectedMin = now()->addDays(6)->timestamp;
    $expectedMax = now()->addDays(8)->timestamp;

    expect($expires)->toBeGreaterThan($expectedMin)->toBeLessThan($expectedMax);
});

test('signedUrl respects custom expiry days', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    $url = $artifact->signedUrl(1);

    $parsed = parse_url($url);
    parse_str($parsed['query'] ?? '', $query);

    $expires = (int) $query['expires'];
    $expectedMax = now()->addDays(2)->timestamp;

    expect($expires)->toBeLessThan($expectedMax);
});

test('signedUrl is valid according to Laravel URL validator', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    $url = $artifact->signedUrl();

    expect(URL::hasValidSignature(
        Request::create($url)
    ))->toBeTrue();
});

// ---- Signed URL Access (serves file) ----

test('valid signed URL serves artifact without authentication', function () {
    Storage::fake('local');
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    Storage::disk('local')->put($artifact->disk_path, 'fake-png-content');

    $url = $artifact->signedUrl();
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $response = $this->get($path);
    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
});

test('expired signed URL is rejected', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    $this->travel(8)->days();

    $this->travelBack();
    $url = URL::temporarySignedRoute(
        'artifacts.show',
        now()->subDay(),
        ['task' => $task->id, 'filename' => $artifact->filename]
    );
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $response = $this->get($path);
    $response->assertStatus(403);
});

test('tampered signed URL is rejected', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    $url = $artifact->signedUrl();
    $tamperedUrl = str_replace('signature=', 'signature=tampered', $url);
    $path = parse_url($tamperedUrl, PHP_URL_PATH).'?'.parse_url($tamperedUrl, PHP_URL_QUERY);

    $response = $this->get($path);
    $response->assertStatus(403);
});

test('authenticated users can access artifacts without signed URL', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    Storage::disk('local')->put($artifact->disk_path, 'fake-png-content');

    $this->actingAs($user);

    $response = $this->get(route('artifacts.show', ['task' => $task->id, 'filename' => $artifact->filename]));
    $response->assertOk();
});

test('unauthenticated user without signed URL is rejected', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->screenshot()->create(['yak_task_id' => $task->id]);

    $response = $this->get(route('artifacts.show', ['task' => $task->id, 'filename' => $artifact->filename]));
    $response->assertStatus(403);
});

test('artifact not found returns 404', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create();

    $this->actingAs($user);

    $response = $this->get(route('artifacts.show', ['task' => $task->id, 'filename' => 'nonexistent.png']));
    $response->assertStatus(404);
});

// ---- Research HTML Iframe Viewer ----

test('research artifact viewer renders iframe for authenticated users', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    $this->actingAs($user);

    $response = $this->get(route('artifacts.viewer', ['task' => $task->id, 'filename' => $artifact->filename]));
    $response->assertOk();
    $response->assertSee('Back to Task #'.$task->id);
    $response->assertSee($artifact->filename);
    $response->assertSee('artifact-iframe', false);
});

test('research artifact viewer requires authentication', function () {
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    $response = $this->get(route('artifacts.viewer', ['task' => $task->id, 'filename' => $artifact->filename]));
    $response->assertRedirect(route('login'));
});

test('viewer shows back-to-task link', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create();
    $artifact = Artifact::factory()->research()->create(['yak_task_id' => $task->id]);

    $this->actingAs($user);

    $response = $this->get(route('artifacts.viewer', ['task' => $task->id, 'filename' => $artifact->filename]));
    $response->assertSee(route('tasks.show', $task));
    $response->assertSee('back-to-task', false);
});
