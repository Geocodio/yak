<?php

use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\PullRequestBodyUpdater;
use App\Services\VideoRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

test('renders a reviewer cut when webm and storyboard both exist', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create();
    $rawVideo = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'walkthrough.webm',
        'disk_path' => "{$task->id}/walkthrough.webm",
    ]);
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm-bytes');
    Storage::disk('artifacts')->put(
        "{$task->id}/storyboard.json",
        json_encode(['version' => 1, 'plan' => (object) [], 'events' => []])
    );

    $this->mock(VideoRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function (string $webm, string $sb, string $out): string {
            file_put_contents($out, 'mp4-bytes');

            return $out;
        });

    (new RenderVideoJob($rawVideo->id))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerCut()->where('yak_task_id', $task->id)->count())->toBe(1);
});

test('no-op when storyboard.json is missing', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create();
    $rawVideo = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'walkthrough.webm',
        'disk_path' => "{$task->id}/walkthrough.webm",
    ]);
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm-bytes');
    // NO storyboard.json

    $this->mock(VideoRenderer::class)->shouldNotReceive('render');

    (new RenderVideoJob($rawVideo->id))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerCut()->where('yak_task_id', $task->id)->count())->toBe(0);
});

test('no-op when raw artifact is missing', function () {
    Storage::fake('artifacts');

    $this->mock(VideoRenderer::class)->shouldNotReceive('render');

    (new RenderVideoJob(999_999))->handle(app(VideoRenderer::class));

    expect(Artifact::videoCuts()->count())->toBe(0);
});

test('no-op when raw artifact has wrong type', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create();
    $screenshot = Artifact::factory()->for($task, 'task')->screenshot()->create();

    $this->mock(VideoRenderer::class)->shouldNotReceive('render');

    (new RenderVideoJob($screenshot->id))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerCut()->where('yak_task_id', $task->id)->count())->toBe(0);
});

test('renders a director cut when tier is director, patches PR body, and marks director_cut_status ready', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/42',
        'director_cut_status' => 'rendering',
    ]);
    $raw = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'director-cut.webm',
        'disk_path' => "{$task->id}/director-cut.webm",
    ]);
    Storage::disk('artifacts')->put("{$task->id}/director-cut.webm", 'webm');
    Storage::disk('artifacts')->put(
        "{$task->id}/storyboard.json",
        json_encode(['version' => 1, 'plan' => (object) [], 'events' => []])
    );

    $this->mock(VideoRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(function ($webm, $sb, $out, $tier) {
            return $tier === 'director' && str_contains($out, 'director-cut.mp4');
        })
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'x');

            return $out;
        });

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('appendDirectorCut')
        ->once()
        ->withArgs(function (string $repo, int $prNumber, string $url): bool {
            return $repo === 'acme/web'
                && $prNumber === 42
                && str_contains($url, 'director-cut.mp4');
        });

    (new RenderVideoJob($raw->id, 'director'))->handle(app(VideoRenderer::class));

    expect(Artifact::where('yak_task_id', $task->id)->where('filename', 'director-cut.mp4')->exists())->toBeTrue();
    expect($task->fresh()->director_cut_status)->toBe('ready');
});

test('director tier still marks director_cut_status ready when PR body patch throws', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/77',
        'director_cut_status' => 'rendering',
    ]);
    $raw = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'director-cut.webm',
        'disk_path' => "{$task->id}/director-cut.webm",
    ]);
    Storage::disk('artifacts')->put("{$task->id}/director-cut.webm", 'webm');
    Storage::disk('artifacts')->put(
        "{$task->id}/storyboard.json",
        json_encode(['version' => 1, 'plan' => (object) [], 'events' => []])
    );

    $this->mock(VideoRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'x');

            return $out;
        });

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('appendDirectorCut')
        ->once()
        ->andThrow(new RuntimeException('GitHub rejected PATCH'));

    // Job completes without re-throwing: render succeeded, PR patch is
    // best-effort.
    (new RenderVideoJob($raw->id, 'director'))->handle(app(VideoRenderer::class));

    expect($task->fresh()->director_cut_status)->toBe('ready');
});

test('director tier skips PR body patch when task has no pr_url', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => null,
        'director_cut_status' => 'rendering',
    ]);
    $raw = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'director-cut.webm',
        'disk_path' => "{$task->id}/director-cut.webm",
    ]);
    Storage::disk('artifacts')->put("{$task->id}/director-cut.webm", 'webm');
    Storage::disk('artifacts')->put(
        "{$task->id}/storyboard.json",
        json_encode(['version' => 1, 'plan' => (object) [], 'events' => []])
    );

    $this->mock(VideoRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'x');

            return $out;
        });

    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('appendDirectorCut');

    (new RenderVideoJob($raw->id, 'director'))->handle(app(VideoRenderer::class));

    expect($task->fresh()->director_cut_status)->toBe('ready');
});

test('reviewer tier does not touch director_cut_status or call the PR updater', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/88',
        'director_cut_status' => null,
    ]);
    $raw = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'walkthrough.webm',
        'disk_path' => "{$task->id}/walkthrough.webm",
    ]);
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm');
    Storage::disk('artifacts')->put(
        "{$task->id}/storyboard.json",
        json_encode(['version' => 1, 'plan' => (object) [], 'events' => []])
    );

    $this->mock(VideoRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'x');

            return $out;
        });

    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('appendDirectorCut');

    (new RenderVideoJob($raw->id, 'reviewer'))->handle(app(VideoRenderer::class));

    expect($task->fresh()->director_cut_status)->toBeNull();
});

test('failed() logs and allows CreatePullRequestJob fallback to raw webm', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create();
    $raw = Artifact::factory()->for($task, 'task')->create([
        'type' => 'video',
        'filename' => 'walkthrough.webm',
        'disk_path' => "{$task->id}/walkthrough.webm",
    ]);

    Log::shouldReceive('error')
        ->once()
        ->with('RenderVideoJob: render failed after retries', Mockery::on(fn (array $ctx): bool => $ctx['artifact'] === $raw->id
            && $ctx['error'] === 'boom'));

    $job = new RenderVideoJob($raw->id);
    $job->failed(new RuntimeException('boom'));

    // No reviewer cut was created, but the raw video artifact is still intact
    // so CreatePullRequestJob's fallback logic can link it.
    expect(Artifact::reviewerCut()->where('yak_task_id', $task->id)->count())->toBe(0)
        ->and(Artifact::where('type', 'video')->where('yak_task_id', $task->id)->count())->toBe(1);
});
