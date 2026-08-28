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

        $isSvg = str_ends_with($path, '.svg');

        // The logo is served unauthenticated from the app's own origin so
        // headless Chrome can fetch it during a render. Direct navigation
        // to this URL is not inert, so an uploaded SVG could otherwise run
        // script with the app's origin. `sandbox` on the CSP blocks script
        // execution, framing, and form submission from the served
        // document; the other headers stop a browser from being coaxed
        // into treating the response as something other than the inert
        // asset it is.
        return $disk->response($path, null, [
            'Content-Type' => $isSvg ? 'image/svg+xml' : 'image/png',
            'Cache-Control' => 'public, max-age=300',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="logo.' . ($isSvg ? 'svg' : 'png') . '"',
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
