<?php

namespace App\Http\Controllers\AppEmbed;

use App\Enums\DeviceKind;
use App\Http\Controllers\Api\App\WebSessionController;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Support\AppEmbedAudit;
use App\Support\AppEmbedRoutes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Trades a hand-off code for the restricted `app-embed` session of the phone's web view.
 *
 * Every refusal is the same plain 403; the reason goes to the log only. The web guard is never logged in
 * and no remember cookie is set.
 */
class SessionController extends Controller
{
    private const FAILURES_PER_MINUTE = 10;

    public function __invoke(Request $request): SymfonyResponse
    {
        $failureKey = 'app-embed-failures:' . $request->ip();

        // The code is checked first: a valid code is never refused by the failure counter, so bad codes from a shared
        // address cannot lock real phones out. Only failed redeems are counted, and past the limit they answer 429.
        $result = $this->redeem($request);

        if (is_string($result)) {
            $isLimited = RateLimiter::tooManyAttempts($failureKey, self::FAILURES_PER_MINUTE);
            RateLimiter::hit($failureKey);
            AppEmbedAudit::log('app_embed.refused', $request, ['reason' => $result]);

            return $isLimited ? $this->plain(429, 'Too Many Attempts.') : $this->plain(403, 'Forbidden.');
        }

        return $result;
    }

    /**
     * The 303 for a redeemed code, or the reason it was refused.
     */
    private function redeem(Request $request): SymfonyResponse|string
    {
        if (! str_contains((string) $request->userAgent(), ' YakApp/')) {
            return 'agent';
        }

        if (! in_array($request->header('Sec-Fetch-Site'), [null, 'none', 'same-origin'], true)) {
            return 'site';
        }

        // The body only: a code in the query string is never read.
        $code = $request->request->get('code');

        if (! is_string($code) || preg_match('/^' . WebSessionController::CODE_PREFIX . '[A-Za-z0-9]{48}$/D', $code) !== 1) {
            return 'malformed';
        }

        $key = WebSessionController::cacheKey($code);

        // Cache::add is atomic: only the first redemption of a code wins, whether or not it then passes the checks.
        if (! Cache::add($key . ':used', 1, 2 * WebSessionController::CODE_TTL_SECONDS)) {
            return 'replayed';
        }

        $payload = Cache::pull($key);

        if (! is_array($payload) || ! is_int($payload['device_id'] ?? null) || ! is_int($payload['user_id'] ?? null) || ! is_string($payload['host'] ?? null) || ! is_string($payload['path'] ?? null)) {
            return 'expired';
        }

        if ($payload['host'] !== $request->getHost()) {
            return 'host';
        }

        $device = Device::query()->find($payload['device_id']);

        if (! $device instanceof Device || $device->kind !== DeviceKind::Phone || $device->isRevoked()) {
            return 'revoked';
        }

        $user = User::query()->find($payload['user_id']);

        if (! $user instanceof User || $device->user_id !== $user->id) {
            return 'user';
        }

        if (! AppEmbedRoutes::isEmbedPath($payload['path'])) {
            return 'path';
        }

        $request->session()->invalidate();
        Auth::guard('app-embed')->login($user, false);
        $request->session()->regenerate();
        $request->session()->put('app_embed', [
            'device_id' => $device->id,
            'expires_at' => now()->addHours(AppEmbedRoutes::SESSION_HOURS)->getTimestamp(),
        ]);

        AppEmbedAudit::log('app_embed.redeemed', $request, ['device_id' => $device->id, 'user_id' => $user->id, 'path' => $payload['path']]);

        return redirect($payload['path'], 303)->header('Cache-Control', 'no-store');
    }

    private function plain(int $status, string $body): Response
    {
        return response($body, $status, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain']);
    }
}
