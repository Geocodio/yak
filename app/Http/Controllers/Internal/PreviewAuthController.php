<?php

namespace App\Http\Controllers\Internal;

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

        $userId = $this->previewAccess->targetKind($hostname) !== null
            ? $this->previewAccess->redeemTicket($hostname, (string) $request->query('ticket', ''))
            : null;

        if ($userId === null) {
            return response('This preview link has expired. Reload the page to sign in again.', 401);
        }

        $to = (string) $request->query('to', '/');
        if (! $this->previewAccess->isSameHostPath($to)) {
            $to = '/';
        }

        return response('', 302)
            ->header('Location', $to)
            ->header('Set-Cookie', $this->previewAccess->setCookieHeader($hostname, $userId));
    }
}
