<?php

namespace App\Http\Controllers\Internal;

use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use App\Services\Threads\Preview\ThreadPreviewStatus;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The waking page polls this through Caddy; it never wakes anything. */
class ThreadPreviewHostStatusController
{
    public function __invoke(Request $request, ThreadPreviewHosts $hosts, PreviewAccess $previewAccess, ThreadPreviewStatus $status): Response
    {
        $hostname = strtolower((string) ($request->header('X-Forwarded-Host') ?? $request->getHost()));
        $cookie = $request->cookie(PreviewAccess::COOKIE_NAME);
        $thread = $hosts->threadForHost($hostname);

        if ($thread === null || ! is_string($cookie) || ! $previewAccess->verifyCookie($hostname, $cookie)) {
            return response()->view('threads.preview.not_found', [], 404);
        }

        return response()->json($status->for($thread));
    }
}
