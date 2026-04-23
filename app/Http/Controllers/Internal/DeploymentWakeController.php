<?php

namespace App\Http\Controllers\Internal;

use App\Models\BranchDeployment;
use App\Services\DeploymentShareTokens;
use App\Services\DeploymentWaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class DeploymentWakeController
{
    private const SHARE_COOKIE_NAME = 'yak_share_session';

    public function __construct(
        private readonly DeploymentWaker $waker,
        private readonly DeploymentShareTokens $tokens,
    ) {}

    public function __invoke(Request $request): Response
    {
        $hostname = $request->header('X-Forwarded-Host') ?? $request->getHost();
        $forwardedUri = $request->header('X-Forwarded-Uri', '/');

        $deployment = BranchDeployment::where('hostname', $hostname)->first();
        if ($deployment === null) {
            return response('Unknown preview hostname.', 404);
        }

        // Branch 1: share token in the URL path → 302 + Set-Cookie.
        $shareToken = $this->extractShareToken($forwardedUri);
        if ($shareToken !== null) {
            if (! $this->tokens->verify($deployment, $shareToken)) {
                return response('Invalid share link.', 401);
            }

            $redirectPath = preg_replace('#^/_share/[^/]+#', '', $forwardedUri);
            if ($redirectPath === '' || $redirectPath === null) {
                $redirectPath = '/';
            }

            $maxAge = $deployment->public_share_expires_at
                ? max(0, (int) now()->diffInSeconds($deployment->public_share_expires_at, false))
                : 0;

            return response('<!doctype html><html><body>Redirecting…</body></html>', 302)
                ->header('Location', $redirectPath)
                ->header('Set-Cookie', sprintf(
                    '%s=%s; Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=%d',
                    self::SHARE_COOKIE_NAME,
                    $this->tokens->cookieValue($deployment),
                    $maxAge,
                ));
        }

        // Branch 2: share-session cookie → honor like OAuth.
        $cookieIn = $request->cookie(self::SHARE_COOKIE_NAME);
        $cookieOk = $cookieIn !== null && $this->tokens->verifyCookie($deployment, $cookieIn);

        // Branch 3: OAuth. Redirect to the dashboard's auth-bounce
        // endpoint which runs in the dashboard's own session context —
        // it can tell whether the user is already authed (redirect to
        // the preview) or needs to sign in (store the preview URL as
        // url.intended, then the Google OAuth callback's
        // redirect()->intended() drops them back on the preview).
        if ($request->user() === null && ! $cookieOk) {
            $returnTo = 'https://' . $hostname . $forwardedUri;
            // Pin the URL generator to the dashboard apex + https.
            // Without this Laravel would build the signed URL against
            // the forwarded preview subdomain (trusted-proxies honors
            // X-Forwarded-Host) on http (the forward_auth sub-request
            // itself is plain HTTP to localhost:8080). Either would
            // cause signature validation to fail when the browser
            // follows the bounce to the apex over https.
            $appUrl = (string) config('app.url');
            URL::forceRootUrl($appUrl);
            URL::forceScheme(parse_url($appUrl, PHP_URL_SCHEME) ?: 'https');
            $bounce = URL::temporarySignedRoute(
                'deployments.auth-bounce',
                now()->addMinutes(10),
                ['to' => $returnTo],
            );

            return redirect($bounce);
        }

        $deployment->update(['last_accessed_at' => now()]);

        $outcome = $this->waker->ensureReady($deployment);

        return match ($outcome['state']) {
            'ready' => response('', 200)
                ->header('X-Upstream-Host', $outcome['host'])
                ->header('X-Upstream-Port', (string) $outcome['port'])
                ->header('X-Yak-Deployment-Id', (string) $deployment->id),

            'pending' => response()->view('deployments.cold_boot_shim', [
                'deployment' => $deployment,
            ], 425),

            'failed' => response()->view('deployments.cold_boot_shim', [
                'deployment' => $deployment,
                'failed' => true,
                'reason' => $outcome['reason'] ?? 'Unknown error',
            ], 502),
        };
    }

    private function extractShareToken(string $uri): ?string
    {
        if (preg_match('#^/_share/([A-Za-z0-9]+)(?:/|$)#', $uri, $m)) {
            return $m[1];
        }

        return null;
    }
}
