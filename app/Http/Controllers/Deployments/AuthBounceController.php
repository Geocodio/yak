<?php

namespace App\Http\Controllers\Deployments;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles the OAuth bounce for preview subdomain visits.
 *
 * The wake endpoint on *.{yak_domain} cannot itself run the login flow
 * (Fortify's RedirectIfAuthenticated would ignore url.intended and send
 * already-authed users to the dashboard home). This controller sits on
 * the dashboard and explicitly:
 *   - already authed: redirects straight to the preview URL.
 *   - anonymous: stores the preview URL as url.intended and redirects
 *     to /login so the Google OAuth callback's redirect()->intended()
 *     drops them on the preview after sign-in.
 */
class AuthBounceController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $to = (string) $request->query('to', '');

        if (! $this->isAllowedPreviewUrl($to)) {
            abort(400, 'Invalid preview URL.');
        }

        if ($request->user() !== null) {
            return redirect()->to($to);
        }

        $request->session()->put('url.intended', $to);

        return redirect()->route('login');
    }

    private function isAllowedPreviewUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        $suffix = (string) config('yak.deployments.hostname_suffix');

        if ($suffix === '') {
            return false;
        }

        // Only accept *.{suffix} — the apex is served by the dashboard
        // itself and doesn't need this bounce flow.
        return str_ends_with($parts['host'], '.' . $suffix);
    }
}
