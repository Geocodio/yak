<?php

namespace App\Http\Middleware;

use App\Enums\DeviceKind;
use App\Models\Device;
use App\Services\Devices\DeviceTokens;
use App\Support\ClientApi;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a CLI or app request by its bearer device token (the route names the kind of device it admits), for this request only (no session, no cookie).
 */
class AuthenticateDevice
{
    public function __construct(private DeviceTokens $deviceTokens, private ThrottleRequests $throttle) {}

    public static function device(Request $request): Device
    {
        /** @var Device */
        return $request->attributes->get('device');
    }

    public function handle(Request $request, Closure $next, string $kind = 'computer'): Response
    {
        $expectedKind = DeviceKind::from($kind);
        $isPhoneRoute = $expectedKind === DeviceKind::Phone;
        $notRecognized = $isPhoneRoute ? 'This device token is not recognized. Pair this device with Yak again.' : 'This device token is not recognized. Run yak login.';
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return $this->reject('unauthenticated', ClientApi::unauthenticatedMessage($isPhoneRoute ? 'app' : 'cli'));
        }

        $device = $this->deviceTokens->resolve($token);

        if ($device === null) {
            return $this->reject('invalid_token', $notRecognized);
        }

        if ($device->kind !== $expectedKind) {
            return $this->reject('invalid_token', $isPhoneRoute ? 'This token does not belong to a phone. Pair this device with Yak again.' : 'This token does not belong to a computer. Run yak login.');
        }

        if ($device->isRevoked()) {
            return $this->reject('device_revoked', $isPhoneRoute ? 'This phone was removed in Yak. Pair it with Yak again.' : 'This computer was removed in Yak. Run yak login to connect it again.');
        }

        $user = $device->user;

        if ($user === null) {
            return $this->reject('invalid_token', $notRecognized);
        }

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subSeconds(60))) {
            $appVersion = $isPhoneRoute ? $this->appVersion($request) : null;
            $device->forceFill(['last_seen_at' => now()] + ($appVersion !== null ? ['app_version' => $appVersion] : []))->saveQuietly();
        }

        $request->attributes->set('device', $device);
        $request->setUserResolver(fn () => $user);
        Auth::guard('web')->setUser($user);

        return $this->throttle->handle($request, $next, $isPhoneRoute ? 'app-device' : 'cli-device');
    }

    /**
     * The version from a `YakApp/<version> (...)` User-Agent, or null when the header is absent or malformed.
     */
    private function appVersion(Request $request): ?string
    {
        return preg_match('/^YakApp\/([0-9A-Za-z.+-]{1,32})(?:\s|$)/', (string) $request->userAgent(), $matches) === 1 ? $matches[1] : null;
    }

    private function reject(string $error, string $message): Response
    {
        return response()->json(['error' => $error, 'message' => $message], 401);
    }
}
