<?php

namespace App\Http\Controllers\Internal;

use App\Models\BranchDeployment;
use App\Services\PreviewAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trades a single-use ticket from the dashboard for the host-only preview
 * cookie, then sends the visitor back to the path they asked for.
 */
class PreviewAuthController
{
    public function __construct(private readonly PreviewAccess $previewAccess) {}

    public function __invoke(Request $request): Response
    {
        $hostname = strtolower((string) ($request->header('X-Forwarded-Host') ?? $request->getHost()));

        $userId = BranchDeployment::where('hostname', $hostname)->exists()
            ? $this->previewAccess->redeemTicket($hostname, (string) $request->query('ticket', ''))
            : null;

        if ($userId === null) {
            return response('This preview link has expired. Reload the page to sign in again.', 401);
        }

        // Only same-host paths: a single leading slash, never //host or /\host.
        $to = (string) $request->query('to', '/');
        if (! preg_match('#^/(?![/\\\\])#', $to)) {
            $to = '/';
        }

        return response('', 302)
            ->header('Location', $to)
            ->header('Set-Cookie', $this->previewAccess->setCookieHeader($hostname, $userId));
    }
}
