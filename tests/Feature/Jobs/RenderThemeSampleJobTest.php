<?php

use App\Jobs\RenderThemeSampleJob;
use App\Models\VideoTheme;
use App\Services\VideoRenderer;
use App\Services\VideoThemeResolver;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

it('queues on yak-render', function (): void {
    expect((new RenderThemeSampleJob)->queue)->toBe('yak-render');
});

it('renders the PreviewWalkthrough composition with the saved theme', function (): void {
    Storage::fake('artifacts');
    VideoTheme::factory()->create(['id' => 1, 'theme' => ['colors' => ['accent' => '#112233']]]);

    Process::fake(['*' => Process::result('', '', 0)]);

    (new RenderThemeSampleJob)->handle(app(VideoRenderer::class), app(VideoThemeResolver::class));

    Process::assertRan(function (PendingProcess $process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'PreviewWalkthrough')
            && str_contains($command, '"accent":"#112233"');
    });

    Storage::disk('artifacts')->assertExists('theme/sample.mp4');
});

it('fails loudly when remotion exits non-zero', function (): void {
    Storage::fake('artifacts');
    Process::fake(['*' => Process::result('', 'boom', 1)]);

    expect(fn () => (new RenderThemeSampleJob)->handle(app(VideoRenderer::class), app(VideoThemeResolver::class)))
        ->toThrow(RuntimeException::class);

    Storage::disk('artifacts')->assertMissing('theme/sample.mp4');
});
