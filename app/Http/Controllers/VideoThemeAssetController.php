<?php

namespace App\Http\Controllers;

use App\Models\VideoTheme;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the two files the theme page produces: the installation logo (which
 * the headless-Chrome render fetches with no session, so it is unauthenticated
 * — it is a brand logo that ends up in public videos anyway) and the sample
 * MP4 (dashboard-only).
 */
class VideoThemeAssetController extends Controller
{
    public function logo(): StreamedResponse
    {
        $path = VideoTheme::query()->find(1)?->logo_path;
        abort_if($path === null, 404);

        $disk = Storage::disk('artifacts');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function sample(): StreamedResponse
    {
        $disk = Storage::disk('artifacts');
        abort_unless($disk->exists('theme/sample.mp4'), 404);

        return $disk->response('theme/sample.mp4', 'yak-theme-sample.mp4', [
            'Content-Type' => 'video/mp4',
        ]);
    }
}
