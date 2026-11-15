<?php

namespace App\Http\Controllers\Api\App\Push;

use App\Enums\PushTokenKind;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Requests\Api\App\Push\StorePushTokenRequest;
use App\Models\DevicePushToken;
use App\Services\Devices\ShortLockWait;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\PushRefusals;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class PushTokenController extends Controller
{
    /**
     * Stores the phone's token for one purpose. A repeat of the same token changes nothing; a token another device held moves here.
     */
    public function update(StorePushTokenRequest $request, PushTokenKind $kind, ApnsConfig $apnsConfig): Response
    {
        $device = AuthenticateDevice::device($request);
        $token = strtolower($request->validated('token'));
        $environment = $request->validated('environment');

        if ($apnsConfig->isConfigured() && $apnsConfig->environment() !== $environment) {
            throw PushRefusals::environmentMismatch($apnsConfig->environment(), $environment);
        }

        ShortLockWait::transaction(function () use ($device, $kind, $token, $environment): void {
            DevicePushToken::query()
                ->where('kind', $kind->value)
                ->where('token', $token)
                ->where('device_id', '!=', $device->id)
                ->delete();

            $row = DevicePushToken::query()->firstOrNew(['device_id' => $device->id, 'kind' => $kind->value]);

            if ($row->token !== $token) {
                $row->last_error = null;
            }

            $row->fill(['token' => $token, 'environment' => $environment])->save();
        }, fn (): Throwable => new HttpException(503, 'Yak is busy with another request for this device. Try again in a few seconds.'));

        return response()->noContent();
    }

    public function destroy(Request $request, PushTokenKind $kind): Response
    {
        DevicePushToken::query()
            ->where('device_id', AuthenticateDevice::device($request)->id)
            ->where('kind', $kind->value)
            ->delete();

        return response()->noContent();
    }
}
