<?php

namespace App\Http\Controllers\Deployments;

use App\Services\PreviewAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles the OAuth bounce for preview subdomain visits.
 *
 * The wake endpoint on *.{yak_domain} cannot itself run the login flow
 * (Fortify's RedirectIfAuthenticated would ignore url.intended and send
 * already-authed users to the dashboard home). This controller sits on
 * the dashboard and explicitly:
 *   - already authed: mints a single-use ticket and redirects to the
 *     preview host's /_yak/auth, which trades it for a host-only cookie.
 *   - anonymous: stores the preview URL as url.intended and redirects
 *     to /login so the Google OAuth callback's redirect()->intended()
 *     drops them on the preview after sign-in.
 */
class AuthBounceController
{
    public function __construct(private readonly PreviewAccess $previewAccess) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $to = (string) $request->query('to', '');

        if (! $this->isAllowedPreviewUrl($to)) {
            abort(400, 'Invalid preview URL.');
        }

        // Expire domain-wide copies of the session/CSRF cookies left over
        // from when SESSION_DOMAIN was .{yak_domain}. The session cookie is
        // host-only now; a leftover Domain cookie of the same name would
        // coexist in the browser and be picked unpredictably.
        $suffix = (string) config('yak.deployments.hostname_suffix');
        foreach (array_unique([(string) config('session.cookie'), 'yak-session', 'XSRF-TOKEN']) as $name) {
            cookie()->queue(cookie()->forget($name, '/', $suffix));
        }

        $user = $request->user();
        if ($user !== null) {
            $parts = parse_url($to);
            if (! is_array($parts) || ! isset($parts['host'])) {
                abort(400, 'Invalid preview URL.');
            }
            $host = strtolower($parts['host']);

            // Authorization: the host must name a deployment or a thread, and
            // the user must be allowed in. A hidden thread host answers exactly
            // like an unknown host.
            $kind = $this->previewAccess->targetKind($host);
            if ($kind === null || ($kind === 'thread' && ! $this->previewAccess->mayEnter($host, $user->id))) {
                abort(404, 'No preview for this host.');
            }

            $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

            return redirect()->to('https://' . $host . '/_yak/auth?' . http_build_query([
                'ticket' => $this->previewAccess->mintTicket($host, $user),
                'to' => $path,
            ]));
        }

        $request->session()->put('url.intended', $to);

        return redirect()->route('login');
    }

    private function isAllowedPreviewUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        // Lower-case scheme + host before comparing: DNS hosts are
        // case-insensitive, while str_ends_with is byte-exact.
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) $parts['host']);
        $suffix = strtolower((string) config('yak.deployments.hostname_suffix'));

        if ($scheme !== 'https' || $suffix === '') {
            return false;
        }

        // Only accept *.{suffix} — the apex is served by the dashboard
        // itself and doesn't need this bounce flow.
        return str_ends_with($host, '.' . $suffix);
    }
}
