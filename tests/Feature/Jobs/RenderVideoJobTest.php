<?php

use App\Enums\NotificationType;
use App\Jobs\RenderVideoJob;
use App\Jobs\SendNotificationJob;
use App\Models\Artifact;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\PullRequestBodyUpdater;
use App\Services\VideoRenderer;
use App\Services\VideoThumbnailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('renders a reviewer cut plus a poster thumbnail when webm and storyboard both exist', function () {
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

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('render')
        ->once()
        ->andReturnUsing(function (string $webm, string $sb, string $out): string {
            file_put_contents($out, 'mp4-bytes');

            return $out;
        });
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(null);

    $this->mock(VideoThumbnailer::class)
        ->shouldReceive('generate')
        ->once()
        ->andReturnUsing(function (string $video, string $thumbOut): string {
            file_put_contents($thumbOut, 'jpg-bytes');

            return $thumbOut;
        });

    (new RenderVideoJob($rawVideo->id))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerCut()->where('yak_task_id', $task->id)->count())->toBe(1);
    expect(Artifact::reviewerThumbnail()->where('yak_task_id', $task->id)->count())->toBe(1);
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

test('patches the PR body with the rendered cut', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/88',
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

    $renderer = $this->mock(VideoRenderer::class);
    $renderer
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'x');

            return $out;
        });
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(null);

    $this->mock(VideoThumbnailer::class)
        ->shouldReceive('generate')
        ->once()
        ->andReturnUsing(function ($v, $out) {
            file_put_contents($out, 'jpg');

            return $out;
        });

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('setReviewerCut')
        ->once()
        ->withArgs(function (string $repo, int $prNumber, string $url, string $filename, ?string $thumbnailUrl): bool {
            return $repo === 'acme/web'
                && $prNumber === 88
                && str_contains($url, 'reviewer-cut.mp4')
                && $filename === 'reviewer-cut.mp4'
                && $thumbnailUrl !== null
                && str_contains($thumbnailUrl, 'reviewer-cut-thumbnail.jpg');
        });

    (new RenderVideoJob($raw->id))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerThumbnail()->where('yak_task_id', $task->id)->exists())->toBeTrue();
});

test('reviewer tier still publishes the mp4 link (without thumbnail) when thumbnail generation fails', function () {
    Storage::fake('artifacts');

    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/99',
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

    $renderer = $this->mock(VideoRenderer::class);
    $renderer
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'mp4');

            return $out;
        });
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(null);

    $this->mock(VideoThumbnailer::class)
        ->shouldReceive('generate')
        ->once()
        ->andThrow(new RuntimeException('ffmpeg missing'));

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('setReviewerCut')
        ->once()
        ->withArgs(fn (string $repo, int $pr, string $url, string $filename, ?string $thumbnailUrl): bool => $thumbnailUrl === null);

    (new RenderVideoJob($raw->id))->handle(app(VideoRenderer::class));

    expect(Artifact::reviewerThumbnail()->where('yak_task_id', $task->id)->exists())->toBeFalse();
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

    $renderer = $this->mock(VideoRenderer::class);
    $renderer
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'x');

            return $out;
        });
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(null);

    $this->mock(VideoThumbnailer::class)
        ->shouldReceive('generate')
        ->once()
        ->andReturnUsing(function ($v, $out) {
            file_put_contents($out, 'jpg');

            return $out;
        });

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('setReviewerCut')
        ->once()
        ->andThrow(new RuntimeException('GitHub rejected PATCH'));

    (new RenderVideoJob($raw->id))->handle(app(VideoRenderer::class));

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

    $renderer = $this->mock(VideoRenderer::class);
    $renderer
        ->shouldReceive('render')
        ->once()
        ->andReturnUsing(function ($w, $s, $out) {
            file_put_contents($out, 'x');

            return $out;
        });
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(null);

    $this->mock(VideoThumbnailer::class)
        ->shouldReceive('generate')
        ->once()
        ->andReturnUsing(function ($v, $out) {
            file_put_contents($out, 'jpg');

            return $out;
        });

    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('setReviewerCut');

    (new RenderVideoJob($raw->id))->handle(app(VideoRenderer::class));

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

    Log::shouldReceive('channel')->with('yak')->andReturnSelf();
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

test('records a rendered metric with size and duration on success', function () {
    Storage::fake('artifacts');
    $task = YakTask::factory()->success()->create();
    $rawVideo = Artifact::factory()->for($task, 'task')->create(['type' => 'video', 'filename' => 'walkthrough.webm', 'disk_path' => "{$task->id}/walkthrough.webm"]);
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm-bytes');
    Storage::disk('artifacts')->put("{$task->id}/storyboard.json", json_encode(['version' => 1, 'plan' => (object) [], 'events' => []]));

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('render')->once()->andReturnUsing(function (string $webm, string $sb, string $out): string {
        file_put_contents($out, str_repeat('x', 2048));

        return $out;
    });
    $renderer->shouldReceive('probeDurationSeconds')->once()->andReturn(33.25);
    $this->mock(VideoThumbnailer::class)->shouldReceive('generate')->once()->andReturnUsing(function (string $v, string $out): string {
        file_put_contents($out, 'jpg');

        return $out;
    });

    (new RenderVideoJob($rawVideo->id))->handle(app(VideoRenderer::class));

    $metric = VideoMetric::where('yak_task_id', $task->id)->sole();
    expect($metric->status)->toBe(VideoMetric::STATUS_RENDERED)
        ->and($metric->output_bytes)->toBe(2048)
        ->and($metric->duration_seconds)->toBe(33.25)
        ->and($metric->artifact_id)->toBe(Artifact::reviewerCut()->where('yak_task_id', $task->id)->sole()->id)
        ->and($metric->render_ms)->toBeGreaterThanOrEqual(0);
});

test('records a failed metric and rethrows when the render throws', function () {
    Storage::fake('artifacts');
    $task = YakTask::factory()->success()->create();
    $rawVideo = Artifact::factory()->for($task, 'task')->create(['type' => 'video', 'filename' => 'walkthrough.webm', 'disk_path' => "{$task->id}/walkthrough.webm"]);
    Storage::disk('artifacts')->put("{$task->id}/walkthrough.webm", 'webm-bytes');
    Storage::disk('artifacts')->put("{$task->id}/storyboard.json", json_encode(['version' => 1, 'plan' => (object) [], 'events' => []]));

    $this->mock(VideoRenderer::class)->shouldReceive('render')->once()->andThrow(new RuntimeException('Remotion render failed (exit 1): boom'));

    expect(fn () => (new RenderVideoJob($rawVideo->id))->handle(app(VideoRenderer::class)))->toThrow(RuntimeException::class);

    $metric = VideoMetric::where('yak_task_id', $task->id)->sole();
    expect($metric->status)->toBe(VideoMetric::STATUS_FAILED)
        ->and($metric->error)->toContain('boom');
});

test('failed() notifies the task channel and marks the PR video line unavailable', function () {
    Queue::fake();
    $task = YakTask::factory()->success()->create(['repo' => 'geocodio-website', 'pr_url' => 'https://github.com/Geocodio/geocodio-website/pull/42']);
    $rawVideo = Artifact::factory()->for($task, 'task')->create(['type' => 'video', 'filename' => 'walkthrough.webm', 'disk_path' => "{$task->id}/walkthrough.webm"]);

    $this->mock(PullRequestBodyUpdater::class)
        ->shouldReceive('setWalkthroughUnavailable')
        ->once()
        ->withArgs(fn (string $repo, int $pr, string $reason): bool => $pr === 42 && str_contains($reason, 'Permission denied'));

    (new RenderVideoJob($rawVideo->id))->failed(new ErrorException('copy(/app/video/public/x.webm): Failed to open stream: Permission denied'));

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) use ($task): bool {
        return $job->task->is($task)
            && $job->type === NotificationType::Error
            && str_contains($job->message, 'walkthrough video')
            && str_contains($job->message, 'Permission denied')
            && str_contains($job->message, 'yak:video:rerender --task=' . $task->id);
    });
});

test('failed() survives a task with no PR and a PR patch failure', function () {
    Queue::fake();
    $task = YakTask::factory()->success()->create(['pr_url' => null]);
    $rawVideo = Artifact::factory()->for($task, 'task')->create(['type' => 'video', 'filename' => 'walkthrough.webm', 'disk_path' => "{$task->id}/walkthrough.webm"]);
    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('setWalkthroughUnavailable');

    (new RenderVideoJob($rawVideo->id))->failed(new RuntimeException('x'));

    Queue::assertPushed(SendNotificationJob::class);
});
