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

    public static function unauthenticatedMessage(string $api): string
    {
        return $api === 'app' ? 'Pair this device with Yak again.' : 'Sign in with yak login.';
    }
}
