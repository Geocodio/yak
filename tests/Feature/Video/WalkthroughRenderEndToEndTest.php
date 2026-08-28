<?php

use App\Jobs\RenderWalkthroughJob;
use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\Mp4ChapterWriter;
use App\Services\PreviewGifGenerator;
use App\Services\RenderQaCheck;
use App\Services\VideoRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

it('renders a real v3 walkthrough end to end', function (): void {
    // The fixture clip is ~11 s over three shots, so the cut lands well
    // under the production 30 s floor. The gate itself is exercised by
    // RenderQaCheckTest; here we only need it not to reject the fixture.
    config()->set('yak.video.duration_bounds', [5, 180]);

    // A real render writes real megabytes. Point the artifacts disk at a
    // per-run root and tear it down afterwards, so the suite never inherits
    // a v3 manifest from this test and starts rendering inside unrelated
    // tests running on the sync queue.
    $artifactsRoot = sys_get_temp_dir() . '/yak-e2e-artifacts-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($artifactsRoot);
    config()->set('filesystems.disks.artifacts.root', $artifactsRoot);
    Storage::forgetDisk('artifacts');

    $renderRoot = sys_get_temp_dir() . '/yak-e2e-render-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($renderRoot);
    config()->set('yak.video.render_staging_path', $renderRoot);

    // Safety net: tear the roots down even if an assertion below fails.
    $this->beforeApplicationDestroyed(function () use ($artifactsRoot, $renderRoot): void {
        File::deleteDirectory($artifactsRoot);
        File::deleteDirectory($renderRoot);
    });

    $task = YakTask::factory()->create(['pr_url' => null]);
    $disk = Storage::disk('artifacts');
    $taskDir = (string) $task->id;

    $fixtures = base_path('video/fixtures/v3');
    $clip = base_path('video/public/v3/fixture-clip.webm');

    File::ensureDirectoryExists($disk->path("{$taskDir}/shots"));
    $disk->put("{$taskDir}/script.json", File::get("{$fixtures}/script.json"));

    $manifest = json_decode(File::get("{$fixtures}/manifest.json"), true);
    foreach ($manifest['shots'] as $index => $shot) {
        $manifest['shots'][$index]['clip'] = "shots/{$shot['id']}.webm";
        File::copy($clip, $disk->path("{$taskDir}/shots/{$shot['id']}.webm"));
    }
    $disk->put("{$taskDir}/manifest.json", (string) json_encode($manifest));

    foreach (['script' => 'script.json', 'manifest' => 'manifest.json'] as $role => $filename) {
        Artifact::create([
            'yak_task_id' => $task->id, 'type' => 'file', 'role' => $role,
            'filename' => $filename, 'disk_path' => "{$taskDir}/{$filename}",
            'size_bytes' => $disk->size("{$taskDir}/{$filename}"),
        ]);
    }
    foreach ($manifest['shots'] as $shot) {
        Artifact::create([
            'yak_task_id' => $task->id, 'type' => 'video', 'role' => 'shot',
            'filename' => "{$shot['id']}.webm", 'disk_path' => "{$taskDir}/shots/{$shot['id']}.webm",
            'size_bytes' => $disk->size("{$taskDir}/shots/{$shot['id']}.webm"),
        ]);
    }

    (new RenderWalkthroughJob($task->id))->handle(
        app(VideoRenderer::class),
        app(RenderQaCheck::class),
        app(PreviewGifGenerator::class),
        app(Mp4ChapterWriter::class),
    );

    $roles = $task->artifacts()->pluck('disk_path', 'role');

    expect($roles)->toHaveKeys(['cut', 'thumbnail', 'preview', 'chapters'])
        ->and($disk->exists($roles['cut']))->toBeTrue()
        ->and($disk->exists($roles['thumbnail']))->toBeTrue()
        ->and($disk->size($roles['preview']))->toBeLessThanOrEqual(PreviewGifGenerator::MAX_BYTES);

    $chapters = json_decode((string) $disk->get($roles['chapters']), true);

    expect($chapters)->toBeArray();
    expect($chapters)->not->toBeEmpty();

    $probe = Process::run([
        'ffprobe', '-v', 'error', '-print_format', 'json', '-show_chapters', $disk->path($roles['cut']),
    ]);

    expect(json_decode($probe->output(), true)['chapters'] ?? [])->not->toBeEmpty();

    File::deleteDirectory($artifactsRoot);
    File::deleteDirectory($renderRoot);
    Storage::forgetDisk('artifacts');

    expect(File::isDirectory($artifactsRoot))->toBeFalse()
        ->and(File::isDirectory($renderRoot))->toBeFalse();
})->group('e2e')->skip(
    fn (): bool => getenv('YAK_E2E_RENDER') !== '1',
    'set YAK_E2E_RENDER=1 to run the real Remotion render',
);
