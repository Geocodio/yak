<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Requests\Api\App\WebSessionRequest;
use App\Support\AppEmbedAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mints the single-use code that the embedded web view trades for a restricted session (POST /app/embed/session).
 * Only a hash of the code is stored, and the code never reaches a log.
 */
class WebSessionController extends Controller
{
    public const CODE_PREFIX = 'yakweb_';

    public const CODE_TTL_SECONDS = 60;

    public static function cacheKey(string $code): string
    {
        return 'app-embed-code:' . hash('sha256', $code);
    }

    public function __invoke(WebSessionRequest $request): JsonResponse
    {
        $device = AuthenticateDevice::device($request);
        $path = $request->embedPath();
        $code = self::CODE_PREFIX . Str::random(48);
        $expiresAt = now()->addSeconds(self::CODE_TTL_SECONDS);

        Cache::put(self::cacheKey($code), [
            'device_id' => $device->id,
            'user_id' => $device->user_id,
            'host' => $request->getHost(),
            'path' => $path,
        ], $expiresAt);

        AppEmbedAudit::log('app_embed.minted', $request, ['device_id' => $device->id, 'user_id' => $device->user_id, 'path' => $path]);

        return response()->json([
            'redeemUrl' => $request->getSchemeAndHttpHost() . route('app.embed.session', [], false),
            'code' => $code,
            'expiresAt' => $expiresAt->toIso8601String(),
        ], 201)->header('Cache-Control', 'no-store');
    }
}
