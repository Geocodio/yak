<?php

use App\Services\PreviewGifGenerator;
use Illuminate\Support\Facades\Process;

it('builds a gif from the title card and the first shot', function (): void {
    Process::fake(['*' => Process::result('', '', 0)]);
    $out = sys_get_temp_dir() . '/preview-' . bin2hex(random_bytes(4)) . '.gif';
    file_put_contents($out, str_repeat('g', 1024));

    (new PreviewGifGenerator)->generate('/tmp/walkthrough.mp4', $out, 8.0);

    Process::assertRanTimes(fn ($process): bool => in_array('ffmpeg', $process->command, strict: true), 2);
    Process::assertRan(function ($process): bool {
        $filter = implode(' ', $process->command);

        return str_contains($filter, 'palettegen')
            && str_contains($filter, 'between(t,0,1.5)')
            && str_contains($filter, 'between(t,8,14)')
            && str_contains($filter, 'fps=12')
            && str_contains($filter, 'scale=720');
    });
});

it('falls back to 640px and 10fps when the gif exceeds the cap', function (): void {
    $out = sys_get_temp_dir() . '/preview-' . bin2hex(random_bytes(4)) . '.gif';
    $calls = 0;
    Process::fake(function () use (&$calls, $out) {
        $calls++;
        // Passes 1-2 produce an oversized gif; passes 3-4 a small one.
        file_put_contents($out, str_repeat('g', $calls <= 2 ? 3_000_000 : 1024));

        return Process::result('', '', 0);
    });

    (new PreviewGifGenerator)->generate('/tmp/walkthrough.mp4', $out, 8.0);

    expect(filesize($out))->toBeLessThan(PreviewGifGenerator::MAX_BYTES);
    Process::assertRanTimes(fn ($process): bool => in_array('ffmpeg', $process->command, strict: true), 4);
});

it('throws when ffmpeg fails', function (): void {
    Process::fake(['*' => Process::result('', 'Unknown filter', 1)]);

    (new PreviewGifGenerator)->generate('/tmp/walkthrough.mp4', sys_get_temp_dir() . '/x.gif', 8.0);
})->throws(RuntimeException::class, 'Unknown filter');

it('uses a single leading range when there are no shots', function (): void {
    Process::fake(['*' => Process::result('', '', 0)]);
    $out = sys_get_temp_dir() . '/preview-' . bin2hex(random_bytes(4)) . '.gif';
    file_put_contents($out, 'g');

    (new PreviewGifGenerator)->generate('/tmp/walkthrough.mp4', $out, null);

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'between(t,0,7.5)'));
});
