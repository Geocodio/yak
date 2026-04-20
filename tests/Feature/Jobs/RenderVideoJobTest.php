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

test('director tier marks director_cut_status ready and leaves the PR body alone', function () {
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

    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('setReviewerCut');

    (new RenderVideoJob($raw->id, 'director'))->handle(app(VideoRenderer::class));

    expect(Artifact::where('yak_task_id', $task->id)->where('filename', 'director-cut.mp4')->exists())->toBeTrue();
    expect($task->fresh()->director_cut_status)->toBe('ready');
});

test('reviewer tier patches the PR body with the rendered cut and does not touch director_cut_status', function () {
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

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('setReviewerCut')
        ->once()
        ->withArgs(function (string $repo, int $prNumber, string $url, string $filename): bool {
            return $repo === 'acme/web'
                && $prNumber === 88
                && str_contains($url, 'reviewer-cut.mp4')
                && $filename === 'reviewer-cut.mp4';
        });

    (new RenderVideoJob($raw->id, 'reviewer'))->handle(app(VideoRenderer::class));

    expect($task->fresh()->director_cut_status)->toBeNull();
});

test('reviewer tier render still completes if PR body patch throws', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/77',
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

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('setReviewerCut')
        ->once()
        ->andThrow(new RuntimeException('GitHub rejected PATCH'));

    (new RenderVideoJob($raw->id, 'reviewer'))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerCut()->where('yak_task_id', $task->id)->exists())->toBeTrue();
});

test('reviewer tier skips PR body patch when task has no pr_url', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => null,
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

    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('setReviewerCut');

    (new RenderVideoJob($raw->id, 'reviewer'))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerCut()->where('yak_task_id', $task->id)->exists())->toBeTrue();
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
