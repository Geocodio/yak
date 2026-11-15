<?php

namespace App\Http\Controllers\Api\App;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\BroadcastAuthRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Broadcast;

/**
 * Signs a socket subscription for the phone's device token. A hidden or
 * missing thread is refused with 403, the answer the Pusher protocol expects.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(BroadcastAuthRequest $request): JsonResponse
    {
        if (! in_array(config('broadcasting.default'), ['reverb', 'pusher'], true)) {
            throw new ThreadActionRefused('unavailable', 'Live updates are off on this Yak. The app refreshes by polling.', [], 503);
        }

        $signed = Broadcast::auth($request);

        return $signed instanceof JsonResponse ? $signed : response()->json($signed);
    }
}
