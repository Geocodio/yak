<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a CLI request by its bearer device token and applies the cli-device limiter.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null) {
            return $this->error('unauthenticated', 'Sign in with yak login.', 401);
        }

        $device = str_starts_with($bearer, 'yakdev_')
            ? Device::query()->where('token_hash', hash('sha256', $bearer))->first()
            : null;

        if ($device === null) {
            return $this->error('invalid_token', 'This device token is not recognised. Run yak login.', 401);
        }

        if ($device->isRevoked()) {
            return $this->error('device_revoked', 'This device was revoked. Run yak login.', 401);
        }

        $request->attributes->set('device', $device);
        $request->setUserResolver(fn () => $device->user);

        $key = 'cli-device:' . $device->id;

        if (RateLimiter::tooManyAttempts($key, 120)) {
            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many requests. Try again shortly.',
            ], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);
        $device->forceFill(['last_seen_at' => now()])->save();

        return $next($request);
    }

    public static function device(Request $request): Device
    {
        $device = $request->attributes->get('device');
        abort_unless($device instanceof Device, 401);

        return $device;
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
