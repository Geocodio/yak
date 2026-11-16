<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Tells which client API (`cli` or `app`) a request belongs to and carries its per-API wording.
 */
class ClientApi
{
    /**
     * @return 'cli'|'app'|null
     */
    public static function for(Request $request): ?string
    {
        return match (true) {
            $request->is('api/cli/*') => 'cli',
            $request->is('api/app/*') => 'app',
            default => null,
        };
    }

    /**
     * The wire error code for an HTTP status no endpoint chose a code for.
     */
    public static function errorCodeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            408 => 'request_timeout',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            419 => 'page_expired',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }

    public static function unauthenticatedMessage(string $api): string
    {
        return $api === 'app' ? 'Pair this device with Yak again.' : 'Sign in with yak login.';
    }
}
