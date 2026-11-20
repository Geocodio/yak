<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The audit trail of the phone's embedded web sessions. A line never carries the code, the session id or the token.
 */
final class AppEmbedAudit
{
    /**
     * @param  array<string, scalar|null>  $context  device_id, user_id, path and reason, whichever are known
     */
    public static function log(string $event, Request $request, array $context = []): void
    {
        Log::channel('yak')->info($event, [
            ...$context,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
