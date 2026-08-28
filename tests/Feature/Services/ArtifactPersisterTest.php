<?php

use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A tiny PNG whose pixels differ per index, so PerceptualHash::dhash
 * does not treat the fixtures as duplicates of one another.
 */
function distinctPngBytes(int $seed): string
{
    $image = imagecreatetruecolor(16, 16);
    for ($x = 0; $x < 16; $x++) {
        for ($y = 0; $y < 16; $y++) {
            $v = ($x * 16 + $y * $seed * 7) % 256;
            imagesetpixel($image, $x, $y, imagecolorallocate($image, $v, ($v * $seed) % 256, 255 - $v));
        }
    }
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake('artifacts');
    Queue::fake();
});

it('creates one Artifact row per file and moves files out of the .yak-artifacts subdir', function () {
    $task = YakTask::factory()->create();

    $artifactsDir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    mkdir($artifactsDir, 0755, true);
    file_put_contents($artifactsDir . '/screenshot.png', 'fake-png-data');
    file_put_contents($artifactsDir . '/report.html', '<html>report</html>');

    $artifacts = ArtifactPersister::persist($task);

    expect($artifacts)->toHaveCount(2);
    expect(Artifact::where('yak_task_id', $task->id)->count())->toBe(2);

    $taskDir = Storage::disk('artifacts')->path((string) $task->id);
    expect(file_exists($taskDir . '/screenshot.png'))->toBeTrue();
    expect(file_exists($taskDir . '/report.html'))->toBeTrue();
    expect(is_dir($artifactsDir))->toBeFalse();
});

it('dispatches RenderVideoJob for webm artifacts so the Remotion pipeline picks them up', function () {
    $task = YakTask::factory()->create();

    $artifactsDir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    mkdir($artifactsDir, 0755, true);
    file_put_contents($artifactsDir . '/walkthrough.webm', 'fake-webm-bytes');
    file_put_contents($artifactsDir . '/storyboard.json', '{"chapters":[]}');

    ArtifactPersister::persist($task);

    $video = Artifact::where('yak_task_id', $task->id)->where('type', 'video')->first();
    expect($video)->not->toBeNull();

    Queue::assertPushed(RenderVideoJob::class, fn ($job) => $job->rawVideoArtifactId === $video->id);
    Queue::assertPushedOn('yak-render', RenderVideoJob::class);
});

it('returns an empty array when no artifacts directory exists', function () {
    $task = YakTask::factory()->create();

    expect(ArtifactPersister::persist($task))->toBe([]);
    expect(Artifact::where('yak_task_id', $task->id)->count())->toBe(0);
});

it('stamps a role on every artifact it persists', function () {
    $task = YakTask::factory()->create();

    $artifactsDir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    mkdir($artifactsDir, 0755, true);
    file_put_contents($artifactsDir . '/walkthrough.webm', 'fake-webm-bytes');
    file_put_contents($artifactsDir . '/storyboard.json', '{"chapters":[]}');
    file_put_contents($artifactsDir . '/notes.txt', 'plain');

    ArtifactPersister::persist($task);

    $roles = Artifact::where('yak_task_id', $task->id)
        ->pluck('role', 'filename')
        ->all();

    expect($roles['walkthrough.webm'])->toBe('raw')
        ->and($roles['storyboard.json'])->toBe('manifest')
        ->and($roles['notes.txt'])->toBeNull();
});

it('persists v3 subdirectories with directory-derived roles', function (): void {
    $task = YakTask::factory()->create();
    $dir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists("{$dir}/shots");
    File::ensureDirectoryExists("{$dir}/stills");
    File::ensureDirectoryExists("{$dir}/vo");
    File::put("{$dir}/script.json", json_encode(['version' => 3, 'shots' => []]));
    File::put("{$dir}/manifest.json", json_encode(['version' => 3, 'shots' => []]));
    File::put("{$dir}/shots/levels.webm", 'webm-bytes');
    File::put("{$dir}/stills/levels.png", 'png-bytes');
    File::put("{$dir}/vo/levels.mp3", 'mp3-bytes');

    ArtifactPersister::persist($task);

    $byRole = $task->artifacts()->get()->keyBy('role');

    expect($byRole['script']->disk_path)->toBe("{$task->id}/script.json")
        ->and($byRole['manifest']->disk_path)->toBe("{$task->id}/manifest.json")
        ->and($byRole['shot']->disk_path)->toBe("{$task->id}/shots/levels.webm")
        ->and($byRole['still']->disk_path)->toBe("{$task->id}/stills/levels.png")
        ->and($byRole['voiceover']->disk_path)->toBe("{$task->id}/vo/levels.mp3")
        ->and(Storage::disk('artifacts')->exists("{$task->id}/shots/levels.webm"))->toBeTrue();
});

it('captions screenshots from the manifest and caps them at five', function (): void {
    $task = YakTask::factory()->create();
    $dir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists("{$dir}/screenshots");

    $entries = [];
    foreach (range(1, 7) as $i) {
        $id = "shot-{$i}";
        $entries[] = ['id' => $id, 'file' => "screenshots/{$id}.png", 'caption' => "Caption {$i}"];
        File::put("{$dir}/screenshots/{$id}.png", distinctPngBytes($i));
    }
    File::put("{$dir}/manifest.json", json_encode(['version' => 3, 'shots' => [], 'screenshots' => $entries]));

    ArtifactPersister::persist($task);

    $screenshots = $task->artifacts()->where('role', 'screenshot')->orderBy('id')->get();

    expect($screenshots)->toHaveCount(5)
        ->and($screenshots->pluck('caption')->all())
        ->toBe(['Caption 1', 'Caption 2', 'Caption 3', 'Caption 4', 'Caption 5'])
        ->and(Storage::disk('artifacts')->exists("{$task->id}/screenshots/shot-6.png"))->toBeFalse();
});

it('never dedups stills against screenshots from an earlier run', function (): void {
    $task = YakTask::factory()->create();
    $bytes = distinctPngBytes(3);

    $firstDir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists("{$firstDir}/stills");
    File::put("{$firstDir}/stills/intro.png", $bytes);

    ArtifactPersister::persist($task);

    $secondDir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists($secondDir);
    File::put("{$secondDir}/intro.png", $bytes);

    ArtifactPersister::persist($task);

    expect($task->artifacts()->where('role', 'still')->count())->toBe(1)
        ->and($task->artifacts()->where('role', 'screenshot')->count())->toBe(1);
});

it('spends the screenshot cap on manifest-named screenshots before top-level ones', function (): void {
    $task = YakTask::factory()->create();
    $dir = Storage::disk('artifacts')->path("{$task->id}/.yak-artifacts");
    File::ensureDirectoryExists("{$dir}/screenshots");

    $entries = [];
    foreach ([3, 2, 1] as $i) {
        $id = "shot-{$i}";
        $entries[] = ['id' => $id, 'file' => "screenshots/{$id}.png", 'caption' => "Caption {$i}"];
        File::put("{$dir}/screenshots/{$id}.png", distinctPngBytes($i));
    }
    File::put("{$dir}/manifest.json", json_encode(['version' => 3, 'shots' => [], 'screenshots' => $entries]));

    foreach (['a', 'b', 'c'] as $index => $name) {
        File::put("{$dir}/legacy-{$name}.png", distinctPngBytes(20 + $index));
    }

    ArtifactPersister::persist($task);

    $screenshots = $task->artifacts()->where('role', 'screenshot')->orderBy('id')->get();

    expect($screenshots)->toHaveCount(5)
        ->and($screenshots->take(3)->pluck('filename')->all())
        ->toBe(['shot-3.png', 'shot-2.png', 'shot-1.png'])
        ->and($screenshots->take(3)->pluck('caption')->all())
        ->toBe(['Caption 3', 'Caption 2', 'Caption 1'])
        ->and($screenshots->slice(3)->pluck('filename')->all())
        ->toBe(['legacy-a.png', 'legacy-b.png'])
        ->and(Storage::disk('artifacts')->exists("{$task->id}/legacy-c.png"))->toBeFalse();
});
