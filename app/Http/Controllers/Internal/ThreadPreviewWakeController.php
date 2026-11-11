<?php

namespace App\Http\Controllers\Internal;

use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewActivity;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caddy's forward-auth target for thread preview hosts. Answers 200 with the
 * upstream headers once the dev server is ready, the waking page while it is
 * not, and the generic not-found page for any host the visitor may not see.
 */
class ThreadPreviewWakeController
{
    public function __construct(
        private readonly ThreadPreviewHosts $hosts,
        private readonly PreviewAccess $previewAccess,
        private readonly ThreadPreviewWaker $waker,
        private readonly ThreadPreviewActivity $activity,
    ) {}

    public function __invoke(Request $request): Response
    {
        $hostname = strtolower((string) ($request->header('X-Forwarded-Host') ?? $request->getHost()));
        $forwardedUri = (string) $request->header('X-Forwarded-Uri', '/');
        $cookie = $request->cookie(PreviewAccess::COOKIE_NAME);
        $thread = $this->hosts->threadForHost($hostname);

        if ($thread === null || (is_string($cookie) && $this->previewAccess->cookieIsHidden($hostname, $cookie))) {
            return $this->notFound();
        }

        if (! is_string($cookie) || ! $this->previewAccess->verifyCookie($hostname, $cookie)) {
            return redirect($this->bounceUrl('https://' . $hostname . $forwardedUri));
        }

        $this->activity->record($thread);

        $outcome = $this->waker->wake($thread, (int) config('yak.threads.preview_hold_seconds'));

        return match ($outcome->state) {
            'ready' => response('', 200)
                ->header('X-Upstream-Host', (string) $outcome->host)
                ->header('X-Upstream-Port', (string) $outcome->port)
                ->header('X-Yak-Thread-Id', (string) $thread->id),
            'waking', 'waiting_for_slot' => response()->view('threads.preview.waking', ['outcome' => $outcome], 425),
            'failing', 'no_manifest', 'destroyed' => response()->view('threads.preview.waking', ['outcome' => $outcome], 502),
            default => $this->notFound(),
        };
    }

    private function notFound(): Response
    {
        return response()->view('threads.preview.not_found', [], 404);
    }

    /** Pinned to the dashboard apex and https so the signature validates after the redirect. */
    private function bounceUrl(string $returnTo): string
    {
        $appUrl = (string) config('app.url');
        URL::forceRootUrl($appUrl);
        URL::forceScheme(parse_url($appUrl, PHP_URL_SCHEME) ?: 'https');

        return URL::temporarySignedRoute('deployments.auth-bounce', now()->addMinutes(10), ['to' => $returnTo]);
    }
}
