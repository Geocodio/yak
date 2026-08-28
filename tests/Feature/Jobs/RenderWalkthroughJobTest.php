<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\DataTransferObjects\WalkthroughTimeline;
use App\Jobs\RenderWalkthroughJob;
use App\Jobs\SendNotificationJob;
use App\Models\Artifact;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\Mp4ChapterWriter;
use App\Services\PreviewGifGenerator;
use App\Services\RenderQaCheck;
use App\Services\RenderQaFailure;
use App\Services\VideoRenderer;
use App\Services\VideoThumbnailer;
use App\Services\VoiceoverGenerator;
use App\Services\WalkthroughPrSection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('artifacts');
    config()->set('yak.channels.github.installation_id', 77);
});

/**
 * @param  array<int, array{shotId: string, width: float}>  $captionOverflow
 */
function walkthroughTimelineFixture(array $captionOverflow = [], float $durationSeconds = 60.0): WalkthroughTimeline
{
    return new WalkthroughTimeline(
        fps: 30,
        width: 1440,
        height: 900,
        durationSeconds: $durationSeconds,
        durationInFrames: (int) round($durationSeconds * 30),
        blocks: [
            ['kind' => 'title', 'id' => 'title', 'startSeconds' => 0.0, 'durationSeconds' => 1.5],
            ['kind' => 'shot', 'id' => 'a', 'startSeconds' => 1.5, 'durationSeconds' => 20.0],
            ['kind' => 'shot', 'id' => 'b', 'startSeconds' => 21.5, 'durationSeconds' => 20.0],
        ],
        chapters: [
            ['title' => 'Setting up', 'startSeconds' => 1.5, 'shots' => []],
            ['title' => 'The fix', 'startSeconds' => 21.5, 'shots' => []],
        ],
        captionOverflow: $captionOverflow,
    );
}

/**
 * A task carrying the four artifact roles a v3 render reads: script,
 * manifest and two shot clips, all present on the fake disk.
 */
function walkthroughTaskFixture(bool $withManifest = true): YakTask
{
    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'pr_url' => 'https://github.com/acme/web/pull/42',
    ]);

    $disk = Storage::disk('artifacts');
    $disk->put("{$task->id}/script.json", json_encode(['version' => 3, 'shots' => []]));
    $disk->put("{$task->id}/shots/a.webm", 'clip-a');
    $disk->put("{$task->id}/shots/b.webm", 'clip-b');

    Artifact::factory()->for($task, 'task')->create([
        'type' => 'file', 'role' => 'script',
        'filename' => 'script.json', 'disk_path' => "{$task->id}/script.json",
    ]);

    if ($withManifest) {
        $disk->put("{$task->id}/manifest.json", json_encode(['version' => 3, 'shots' => []]));
        Artifact::factory()->for($task, 'task')->create([
            'type' => 'file', 'role' => 'manifest',
            'filename' => 'manifest.json', 'disk_path' => "{$task->id}/manifest.json",
        ]);
    }

    foreach (['a', 'b'] as $shot) {
        Artifact::factory()->for($task, 'task')->create([
            'type' => 'video', 'role' => 'shot',
            'filename' => "{$shot}.webm", 'disk_path' => "{$task->id}/shots/{$shot}.webm",
        ]);
    }

    return $task;
}

function runRenderWalkthroughJob(YakTask $task): void
{
    (new RenderWalkthroughJob($task->id))->handle(
        app(VideoRenderer::class),
        app(RenderQaCheck::class),
        app(PreviewGifGenerator::class),
        app(Mp4ChapterWriter::class),
        app(VoiceoverGenerator::class),
    );
}

/**
 * Mock GitHub so the PR body PATCH can be inspected. Returns a reference
 * that holds the patched body once the job publishes.
 *
 * @return array{body: string|null}
 */
function fakeGitHubPrBody(): object
{
    $captured = new stdClass;
    $captured->body = null;

    $github = test()->mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')->andReturn(['body' => "## Yak Automated PR\n\nSummary."]);
    $github->shouldReceive('updatePullRequest')->andReturnUsing(
        function (int $installationId, string $repo, int $number, array $data) use ($captured): array {
            $captured->body = (string) $data['body'];

            return ['body' => $captured->body];
        }
    );

    return $captured;
}

it('renders, gates and publishes the walkthrough', function (): void {
    $task = walkthroughTaskFixture();
    $timeline = walkthroughTimelineFixture();
    $captured = fakeGitHubPrBody();

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('timeline')->once()->andReturn($timeline);
    $renderer->shouldReceive('renderWalkthrough')->once()->andReturnUsing(
        function (string $script, string $manifest, array $clips, ?array $vo, array $theme, ?string $origin, string $output): string {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, 'mp4-bytes');

            return $output;
        }
    );
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(60.0);

    $this->mock(RenderQaCheck::class)->shouldReceive('assertPasses')->once();

    $this->mock(PreviewGifGenerator::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $mp4, string $output): string {
            File::put($output, 'gif-bytes');

            return $output;
        });

    $this->mock(VideoThumbnailer::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $video, string $output): string {
            File::put($output, 'jpg-bytes');

            return $output;
        });

    $this->mock(Mp4ChapterWriter::class)->shouldReceive('write')->once();

    runRenderWalkthroughJob($task);

    expect(Artifact::where('yak_task_id', $task->id)->cut()->count())->toBe(1)
        ->and(Artifact::where('yak_task_id', $task->id)->thumbnail()->count())->toBe(1)
        ->and(Artifact::where('yak_task_id', $task->id)->preview()->count())->toBe(1)
        ->and(Artifact::where('yak_task_id', $task->id)->role('chapters')->count())->toBe(1);

    expect(json_decode(Storage::disk('artifacts')->get("{$task->id}/chapters.json"), true))
        ->toBe($timeline->chapters);

    $metric = VideoMetric::where('yak_task_id', $task->id)->sole();
    expect($metric->status)->toBe(VideoMetric::STATUS_RENDERED)
        ->and($metric->output_bytes)->toBeGreaterThan(0)
        ->and($metric->duration_seconds)->toBe(60.0);

    expect($captured->body)->toContain(WalkthroughPrSection::MARKER_START)
        ->and($captured->body)->toContain('Setting up')
        ->and($captured->body)->toContain('![walkthrough preview]');
});

it('rejects a caption overflow before spending a render', function (): void {
    $task = walkthroughTaskFixture();

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('timeline')->once()
        ->andReturn(walkthroughTimelineFixture(captionOverflow: [['shotId' => 'b', 'width' => 980.0]]));
    $renderer->shouldReceive('renderWalkthrough')->never();
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(null);

    $this->mock(RenderQaCheck::class)->shouldReceive('assertPasses')->never();

    expect(fn () => runRenderWalkthroughJob($task))->toThrow(RenderQaFailure::class);

    $metric = VideoMetric::where('yak_task_id', $task->id)->sole();
    expect($metric->status)->toBe(VideoMetric::STATUS_FAILED)
        ->and($metric->error)->toContain('caption too long for its box: b');

    expect(Artifact::where('yak_task_id', $task->id)->role('shot')->count())->toBe(2)
        ->and(Artifact::where('yak_task_id', $task->id)->role('chapters')->count())->toBe(1)
        ->and(Artifact::where('yak_task_id', $task->id)->cut()->count())->toBe(0);
});

it('keeps a cut that fails QA out of the artifacts', function (): void {
    $task = walkthroughTaskFixture();

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('timeline')->once()->andReturn(walkthroughTimelineFixture());
    $renderer->shouldReceive('renderWalkthrough')->once()->andReturnUsing(
        function (string $script, string $manifest, array $clips, ?array $vo, array $theme, ?string $origin, string $output): string {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, 'mp4-bytes');

            return $output;
        }
    );
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(60.0);

    $this->mock(RenderQaCheck::class)->shouldReceive('assertPasses')->once()
        ->andThrow(new RenderQaFailure('shots a and b render identical frames'));

    expect(fn () => runRenderWalkthroughJob($task))->toThrow(RenderQaFailure::class);

    expect(Artifact::where('yak_task_id', $task->id)->cut()->count())->toBe(0);

    $metric = VideoMetric::where('yak_task_id', $task->id)->sole();
    expect($metric->status)->toBe(VideoMetric::STATUS_FAILED)
        ->and($metric->error)->toContain('render identical frames');
});

it('is a quiet no-op when the manifest artifact is gone', function (): void {
    $task = walkthroughTaskFixture(withManifest: false);

    $this->mock(VideoRenderer::class)->shouldNotReceive('timeline');

    runRenderWalkthroughJob($task);

    expect(Artifact::where('yak_task_id', $task->id)->cut()->count())->toBe(0)
        ->and(Artifact::where('yak_task_id', $task->id)->role('chapters')->count())->toBe(0)
        ->and(VideoMetric::where('yak_task_id', $task->id)->count())->toBe(0);
});

it('falls back to the poster thumbnail when the preview gif fails', function (): void {
    $task = walkthroughTaskFixture();
    $captured = fakeGitHubPrBody();

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('timeline')->once()->andReturn(walkthroughTimelineFixture());
    $renderer->shouldReceive('renderWalkthrough')->once()->andReturnUsing(
        function (string $script, string $manifest, array $clips, ?array $vo, array $theme, ?string $origin, string $output): string {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, 'mp4-bytes');

            return $output;
        }
    );
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(60.0);

    $this->mock(RenderQaCheck::class)->shouldReceive('assertPasses')->once();
    $this->mock(Mp4ChapterWriter::class)->shouldReceive('write')->once();

    $this->mock(PreviewGifGenerator::class)->shouldReceive('generate')->once()
        ->andThrow(new RuntimeException('gifski exploded'));

    $this->mock(VideoThumbnailer::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $video, string $output): string {
            File::put($output, 'jpg-bytes');

            return $output;
        });

    runRenderWalkthroughJob($task);

    expect(Artifact::where('yak_task_id', $task->id)->cut()->count())->toBe(1)
        ->and(Artifact::where('yak_task_id', $task->id)->preview()->count())->toBe(0);

    expect(VideoMetric::where('yak_task_id', $task->id)->sole()->status)
        ->toBe(VideoMetric::STATUS_RENDERED);

    expect($captured->body)->toContain('![walkthrough poster]')
        ->and($captured->body)->not->toContain('![walkthrough preview]');
});

it('notifies and marks the PR when the job finally fails', function (): void {
    Bus::fake();

    $task = walkthroughTaskFixture();
    $captured = fakeGitHubPrBody();

    (new RenderWalkthroughJob($task->id))->failed(new RuntimeException('boom'));

    Bus::assertDispatched(SendNotificationJob::class);

    expect($captured->body)->toContain('Video walkthrough unavailable')
        ->and($captured->body)->toContain('boom');
});

it('generates voiceover before rendering when the task has none', function (): void {
    config()->set('yak.video.elevenlabs.api_key', 'test-key');

    $task = walkthroughTaskFixture();
    Storage::disk('artifacts')->put(
        "{$task->id}/script.json",
        json_encode(['version' => 3, 'intro' => 'Welcome to the walkthrough.', 'shots' => []]),
    );

    Http::fake([
        'api.elevenlabs.io/*' => Http::response('mp3-bytes', 200),
    ]);

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('timeline')->once()->andReturn(walkthroughTimelineFixture());
    $renderer->shouldReceive('renderWalkthrough')->once()->andReturnUsing(
        function (string $script, string $manifest, array $clips, ?array $vo, array $theme, ?string $origin, string $output): string {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, 'mp4-bytes');

            return $output;
        }
    );
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(60.0);

    $this->mock(RenderQaCheck::class)->shouldReceive('assertPasses')->once();
    $this->mock(PreviewGifGenerator::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $mp4, string $output): string {
            File::put($output, 'gif-bytes');

            return $output;
        });
    $this->mock(VideoThumbnailer::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $video, string $output): string {
            File::put($output, 'jpg-bytes');

            return $output;
        });
    $this->mock(Mp4ChapterWriter::class)->shouldReceive('write')->once();

    runRenderWalkthroughJob($task);

    expect(Artifact::where('yak_task_id', $task->id)->role('voiceover')->count())->toBeGreaterThan(0);

    $metric = VideoMetric::where('yak_task_id', $task->id)->sole();
    expect($metric->status)->toBe(VideoMetric::STATUS_RENDERED)
        ->and($metric->tts_characters)->not->toBeNull();
});

it('does not regenerate voiceover when the task already has voiceover artifacts', function (): void {
    config()->set('yak.video.elevenlabs.api_key', 'test-key');

    $task = walkthroughTaskFixture();
    Storage::disk('artifacts')->put(
        "{$task->id}/script.json",
        json_encode(['version' => 3, 'intro' => 'Welcome to the walkthrough.', 'shots' => []]),
    );

    Storage::disk('artifacts')->put("{$task->id}/vo/intro.mp3", 'existing-mp3-bytes');
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'file', 'role' => 'voiceover',
        'filename' => 'intro.mp3', 'disk_path' => "{$task->id}/vo/intro.mp3",
    ]);

    Http::fake();

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('timeline')->once()->andReturn(walkthroughTimelineFixture());
    $renderer->shouldReceive('renderWalkthrough')->once()->andReturnUsing(
        function (string $script, string $manifest, array $clips, ?array $vo, array $theme, ?string $origin, string $output): string {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, 'mp4-bytes');

            return $output;
        }
    );
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(60.0);

    $this->mock(RenderQaCheck::class)->shouldReceive('assertPasses')->once();
    $this->mock(PreviewGifGenerator::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $mp4, string $output): string {
            File::put($output, 'gif-bytes');

            return $output;
        });
    $this->mock(VideoThumbnailer::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $video, string $output): string {
            File::put($output, 'jpg-bytes');

            return $output;
        });
    $this->mock(Mp4ChapterWriter::class)->shouldReceive('write')->once();

    runRenderWalkthroughJob($task);

    Http::assertNothingSent();

    expect(Artifact::where('yak_task_id', $task->id)->role('voiceover')->count())->toBe(1);
});

it('renders captions-only when voiceover generation fails', function (): void {
    config()->set('yak.video.elevenlabs.api_key', 'test-key');

    $task = walkthroughTaskFixture();
    Storage::disk('artifacts')->put(
        "{$task->id}/script.json",
        json_encode(['version' => 3, 'intro' => 'Welcome to the walkthrough.', 'shots' => []]),
    );

    Http::fake(fn () => Http::response('no', 500));

    $renderer = $this->mock(VideoRenderer::class);
    $renderer->shouldReceive('timeline')->once()->andReturn(walkthroughTimelineFixture());
    $renderer->shouldReceive('renderWalkthrough')->once()->andReturnUsing(
        function (string $script, string $manifest, array $clips, ?array $vo, array $theme, ?string $origin, string $output): string {
            File::ensureDirectoryExists(dirname($output));
            File::put($output, 'mp4-bytes');

            return $output;
        }
    );
    $renderer->shouldReceive('probeDurationSeconds')->andReturn(60.0);

    $this->mock(RenderQaCheck::class)->shouldReceive('assertPasses')->once();
    $this->mock(PreviewGifGenerator::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $mp4, string $output): string {
            File::put($output, 'gif-bytes');

            return $output;
        });
    $this->mock(VideoThumbnailer::class)->shouldReceive('generate')->once()
        ->andReturnUsing(function (string $video, string $output): string {
            File::put($output, 'jpg-bytes');

            return $output;
        });
    $this->mock(Mp4ChapterWriter::class)->shouldReceive('write')->once();

    runRenderWalkthroughJob($task);

    expect(Artifact::where('yak_task_id', $task->id)->role('voiceover')->count())->toBe(0)
        ->and(Artifact::where('yak_task_id', $task->id)->cut()->count())->toBe(1);

    $metric = VideoMetric::where('yak_task_id', $task->id)->sole();
    expect($metric->status)->toBe(VideoMetric::STATUS_RENDERED)
        ->and($metric->tts_characters)->toBeNull();
});
