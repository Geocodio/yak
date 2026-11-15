<?php

namespace App\Http\Controllers\Api\App\Threads\Preview;

use App\Enums\ThreadOwnerSide;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\PreviewTicketRequest;
use App\Models\Thread;
use App\Services\PreviewAccess;
use App\Services\Threads\Preview\ThreadPreviewHosts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Mints a single-use link that trades for the per-preview cookie in the app's
 * web view. Stores nothing, so a repeat after a lost response is harmless.
 */
class PreviewTicketController extends Controller
{
    public function __invoke(PreviewTicketRequest $request, Thread $thread, PreviewAccess $access, ThreadPreviewHosts $hosts): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, 'This thread runs on a local machine.');
        }

        if ((string) config('yak.deployments.hostname_suffix') === '') {
            abort(503, 'Previews are switched off on this Yak.');
        }

        $user = $request->user() ?? abort(401);
        $host = $hosts->hostnameFor($thread);
        abort_unless($access->mayEnter($host, $user->id), 404);

        return response()->json([
            'url' => $access->ticketUrl($host, $user, $request->previewPath()),
            'expiresAt' => now()->addSeconds(PreviewAccess::TICKET_TTL_SECONDS)->toIso8601String(),
        ]);
    }
}
