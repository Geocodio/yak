<?php

use App\Models\VideoTheme;
use App\Services\VideoRenderer;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    config()->set('yak.video.render_staging_path', sys_get_temp_dir() . '/yak-render-test');
});

/**
 * @return array{0: string, 1: string, 2: array<string, string>}
 */
function writeV3Inputs(): array
{
    $dir = sys_get_temp_dir() . '/yak-v3-' . bin2hex(random_bytes(4));
    mkdir($dir . '/shots', 0775, true);
    file_put_contents($dir . '/script.json', json_encode(['version' => 3, 'title' => 'T', 'shots' => [['id' => 'a', 'chapter' => 'C', 'say' => 'S']]]));
    file_put_contents($dir . '/manifest.json', json_encode([
        'version' => 3, 'width' => 1440, 'height' => 900, 'base' => 'http://127.0.0.1:8899',
        'shots' => [['id' => 'a', 'clip' => 'shots/a.webm', 'start' => 0, 'end' => 4, 'rect' => null, 'url' => 'http://127.0.0.1:8899/']],
    ]));
    file_put_contents($dir . '/shots/a.webm', 'clip-bytes');

    return [$dir . '/script.json', $dir . '/manifest.json', ['a' => $dir . '/shots/a.webm']];
}

it('renders WalkthroughV3 with staged clips and the theme props', function (): void {
    Process::fake(['*' => Process::result('', '', 0)]);
    [$script, $manifest, $clips] = writeV3Inputs();

    $out = sys_get_temp_dir() . '/out.mp4';

    (new VideoRenderer(base_path('video')))->renderWalkthrough(
        scriptPath: $script,
        manifestPath: $manifest,
        clipPaths: $clips,
        voiceover: null,
        theme: config('yak.video.theme'),
        publicOrigin: 'https://www.example.com',
        outputPath: $out,
    );

    Process::assertRan(function ($process): bool {
        $command = $process->command;
        expect($command)->toContain('WalkthroughV3');

        $props = null;
        foreach ($command as $argument) {
            if (str_starts_with($argument, '--props=')) {
                $props = json_decode(substr($argument, strlen('--props=')), true);
            }
        }

        expect($props)->not->toBeNull()
            ->and($props['publicOrigin'])->toBe('https://www.example.com')
            ->and($props['voiceover'])->toBeNull()
            ->and($props['theme']['colors']['accent'])->toBe('#c4744a')
            ->and($props['manifest']['shots'][0]['clip'])->toBe('shots/a.webm')
            ->and($props['script']['title'])->toBe('T');

        return true;
    });
});

it('renders with the saved theme row layered over the defaults', function (): void {
    VideoTheme::factory()->create([
        'id' => 1,
        'theme' => ['colors' => ['accent' => '#112233']],
    ]);

    Process::fake(['*' => Process::result('', '', 0)]);
    [$script, $manifest, $clips] = writeV3Inputs();

    $out = sys_get_temp_dir() . '/out.mp4';

    (new VideoRenderer(base_path('video')))->renderWalkthrough(
        scriptPath: $script,
        manifestPath: $manifest,
        clipPaths: $clips,
        voiceover: null,
        theme: config('yak.video.theme'),
        publicOrigin: 'https://www.example.com',
        outputPath: $out,
    );

    Process::assertRan(function ($process): bool {
        $command = $process->command;

        $props = null;
        foreach ($command as $argument) {
            if (str_starts_with($argument, '--props=')) {
                $props = json_decode(substr($argument, strlen('--props=')), true);
            }
        }

        expect($props)->not->toBeNull()
            ->and($props['theme']['colors']['accent'])->toBe('#112233')
            ->and($props['theme']['colors']['background'])->toBe('#f5f0e8');

        return true;
    });
});

it('throws naming the manifest shots whose clip is missing', function (): void {
    Process::fake(['*' => Process::result('', '', 0)]);
    [$script, $manifest] = writeV3Inputs();

    (new VideoRenderer(base_path('video')))->renderWalkthrough(
        $script, $manifest, [], null, config('yak.video.theme'), null, sys_get_temp_dir() . '/out.mp4'
    );
})->throws(RuntimeException::class, 'no clip on disk for manifest shot(s): a');

it('throws rather than rendering a cut that silently diverges from the timeline', function (): void {
    Process::fake(['*' => Process::result('', '', 0)]);
    [$script, $manifest, $clips] = writeV3Inputs();

    $manifestData = json_decode((string) file_get_contents($manifest), true);
    $manifestData['shots'][] = [
        'id' => 'b', 'clip' => 'shots/b.webm', 'start' => 4, 'end' => 8,
        'rect' => null, 'url' => 'http://127.0.0.1:8899/b',
    ];
    file_put_contents($manifest, (string) json_encode($manifestData));

    // Shot `a` has a clip, shot `b` does not: rendering only `a` would make
    // the cut shorter than the timeline and chapters.json both describe.
    (new VideoRenderer(base_path('video')))->renderWalkthrough(
        $script, $manifest, $clips, null, config('yak.video.theme'), null, sys_get_temp_dir() . '/out.mp4'
    );
})->throws(RuntimeException::class, 'no clip on disk for manifest shot(s): b');

it('throws when remotion fails', function (): void {
    Process::fake(['*' => Process::result('', 'Error: composition not found', 1)]);
    [$script, $manifest, $clips] = writeV3Inputs();

    (new VideoRenderer(base_path('video')))->renderWalkthrough(
        $script, $manifest, $clips, null, config('yak.video.theme'), null, sys_get_temp_dir() . '/out.mp4'
    );
})->throws(RuntimeException::class, 'composition not found');

it('cleans up the staging directory', function (): void {
    Process::fake(['*' => Process::result('', '', 0)]);
    [$script, $manifest, $clips] = writeV3Inputs();

    (new VideoRenderer(base_path('video')))->renderWalkthrough(
        $script, $manifest, $clips, null, config('yak.video.theme'), null, sys_get_temp_dir() . '/out.mp4'
    );

    $root = config('yak.video.render_staging_path');

    expect(glob($root . '/*') ?: [])->toBe([]);
});
