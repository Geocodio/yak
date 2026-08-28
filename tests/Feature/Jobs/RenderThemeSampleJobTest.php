<?php

use App\Jobs\RenderThemeSampleJob;
use App\Models\VideoTheme;
use App\Services\VideoRenderer;
use App\Services\VideoThemeResolver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * `Process::fake()` never actually runs Remotion, so nothing writes the
 * staged output file. Stand one in so the success path has real bytes to
 * publish — without it the assertions below prove nothing.
 */
function stageThemeSampleOutput(string $bytes = 'mp4-bytes'): string
{
    $output = storage_path('app/private/theme-sample.mp4');

    if (! is_dir(dirname($output))) {
        mkdir(dirname($output), 0775, true);
    }

    file_put_contents($output, $bytes);

    return $output;
}

function runThemeSampleJob(): void
{
    (new RenderThemeSampleJob)->handle(app(VideoRenderer::class), app(VideoThemeResolver::class));
}

afterEach(function (): void {
    @unlink(storage_path('app/private/theme-sample.mp4'));
});

it('queues on yak-render', function (): void {
    expect((new RenderThemeSampleJob)->queue)->toBe('yak-render');
});

it('renders the PreviewWalkthrough composition with the saved theme', function (): void {
    Storage::fake('artifacts');
    VideoTheme::factory()->create(['id' => 1, 'theme' => ['colors' => ['accent' => '#112233']]]);

    Process::fake(['*' => Process::result('', '', 0)]);
    stageThemeSampleOutput();

    runThemeSampleJob();

    Process::assertRan(function (PendingProcess $process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'PreviewWalkthrough')
            && str_contains($command, '"accent":"#112233"');
    });

    Storage::disk('artifacts')->assertExists('theme/sample.mp4');
    expect(Storage::disk('artifacts')->get('theme/sample.mp4'))->toBe('mp4-bytes');
});

it('removes the staged output once it is published', function (): void {
    Storage::fake('artifacts');
    Process::fake(['*' => Process::result('', '', 0)]);
    $output = stageThemeSampleOutput();

    runThemeSampleJob();

    expect(file_exists($output))->toBeFalse();
});

it('fails loudly when remotion exits non-zero', function (): void {
    Storage::fake('artifacts');
    Process::fake(['*' => Process::result('', 'boom', 1)]);

    expect(fn () => runThemeSampleJob())->toThrow(RuntimeException::class);

    Storage::disk('artifacts')->assertMissing('theme/sample.mp4');
});

it('refuses to publish when remotion exits zero without writing anything', function (): void {
    Storage::fake('artifacts');
    Process::fake(['*' => Process::result('', '', 0)]);

    expect(fn () => runThemeSampleJob())
        ->toThrow(RuntimeException::class, 'produced no output');

    Storage::disk('artifacts')->assertMissing('theme/sample.mp4');
});

it('refuses to publish a zero-byte render', function (): void {
    Storage::fake('artifacts');
    Process::fake(['*' => Process::result('', '', 0)]);
    stageThemeSampleOutput('');

    expect(fn () => runThemeSampleJob())
        ->toThrow(RuntimeException::class, 'produced no output');

    Storage::disk('artifacts')->assertMissing('theme/sample.mp4');
});

it('clears the in-flight flag whether the render succeeds or fails', function (): void {
    Storage::fake('artifacts');
    Process::fake(['*' => Process::result('', '', 0)]);

    Cache::put(RenderThemeSampleJob::IN_FLIGHT_KEY, true, 1800);
    stageThemeSampleOutput();
    runThemeSampleJob();

    expect(Cache::has(RenderThemeSampleJob::IN_FLIGHT_KEY))->toBeFalse();

    Cache::put(RenderThemeSampleJob::IN_FLIGHT_KEY, true, 1800);
    Process::fake(['*' => Process::result('', 'boom', 1)]);

    expect(fn () => runThemeSampleJob())->toThrow(RuntimeException::class)
        ->and(Cache::has(RenderThemeSampleJob::IN_FLIGHT_KEY))->toBeFalse();
});
