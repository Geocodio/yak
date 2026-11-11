<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Services\Devices\DeviceTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a CLI request by its bearer device token, for this request only (no session, no cookie).
 */
class AuthenticateDevice
{
    public function __construct(private DeviceTokens $deviceTokens, private ThrottleRequests $throttle) {}

    public static function device(Request $request): Device
    {
        /** @var Device */
        return $request->attributes->get('device');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->reject('unauthenticated', 'Sign in with yak login.');
        }

        $device = $this->deviceTokens->resolve($token);

        if ($device === null) {
            return $this->reject('invalid_token', 'This device token is not recognized. Run yak login.');
        }

        if ($device->isRevoked()) {
            return $this->reject('device_revoked', 'This computer was removed in Yak. Run yak login to connect it again.');
        }

        $user = $device->user;

        if ($user === null) {
            return $this->reject('invalid_token', 'This device token is not recognized. Run yak login.');
        }

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subSeconds(60))) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $request->attributes->set('device', $device);
        $request->setUserResolver(fn () => $user);
        Auth::guard('web')->setUser($user);

        return $this->throttle->handle($request, $next, 'cli-device');
    }

    private function reject(string $error, string $message): Response
    {
        return response()->json(['error' => $error, 'message' => $message], 401);
    }
}
