<?php

namespace App\Jobs;

use App\Services\VideoRenderer;
use App\Services\VideoThemeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Renders the `PreviewWalkthrough` composition with the installation's saved
 * theme so an installer can download an mp4 and confirm fonts and logo
 * survive a real server render (spec §9).
 */
class RenderThemeSampleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 900;

    /**
     * Cache key the settings page sets before dispatching, so a second
     * click cannot pile another 15-minute render onto the shared render
     * queue while this one is still in flight.
     */
    public const IN_FLIGHT_KEY = 'video-theme:sample-render-in-flight';

    /** Safety net: outlive one attempt's timeout, then let the flag lapse. */
    public const IN_FLIGHT_TTL_SECONDS = 1800;

    public function __construct()
    {
        $this->onQueue('yak-render');
    }

    public function handle(VideoRenderer $renderer, VideoThemeResolver $resolver): void
    {
        $theme = $resolver->resolve();
        $output = storage_path('app/private/theme-sample.mp4');

        if (! is_dir(dirname($output)) && ! mkdir(dirname($output), 0775, true) && ! is_dir(dirname($output))) {
            throw new RuntimeException("cannot create staging dir for {$output}");
        }

        $props = json_encode(['theme' => $theme], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $result = Process::path($renderer->videoDir)
                ->timeout(600)
                ->run([
                    'npx', 'remotion', 'render',
                    'src/index.ts', 'PreviewWalkthrough', $output,
                    '--props=' . $props,
                ]);

            if (! $result->successful()) {
                throw new RuntimeException(
                    "Theme sample render failed (exit {$result->exitCode()}): {$result->errorOutput()}"
                );
            }

            // Remotion has been seen to exit 0 having written nothing.
            // Publishing that silently hands the installer a 0-byte mp4
            // with no log line, so treat it as a failed render.
            if (! is_file($output) || filesize($output) === 0) {
                throw new RuntimeException(
                    "Theme sample render produced no output at {$output} despite exiting 0"
                );
            }

            $stream = fopen($output, 'rb');

            if ($stream === false) {
                throw new RuntimeException("Theme sample render output at {$output} is unreadable");
            }

            try {
                // Streamed rather than read whole: an mp4 can be tens of
                // megabytes and the render worker's memory_limit is not.
                if (! Storage::disk('artifacts')->writeStream('theme/sample.mp4', $stream)) {
                    throw new RuntimeException('Could not publish the theme sample to the artifacts disk');
                }
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($output);
            Cache::forget(self::IN_FLIGHT_KEY);
        }
    }

    /**
     * Retries are exhausted: log it. There is no task or PR to notify here —
     * this is a manual, installer-triggered preview — so the only signal is
     * the log and the download link simply never appearing.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('yak')->error('RenderThemeSampleJob: sample render failed after retries', [
            'error' => $e->getMessage(),
        ]);
    }
}
