<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\DeviceKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StartDeviceFlowRequest;
use App\Services\Devices\DeviceFlow;
use Illuminate\Http\JsonResponse;

class DeviceCodeController extends Controller
{
    public function __invoke(StartDeviceFlowRequest $request, DeviceFlow $deviceFlow): JsonResponse
    {
        ['device_code' => $deviceCode, 'authorization' => $authorization] = $deviceFlow->start([
            'name' => $request->validated('name'),
            'os' => $request->validated('os_version'),
            'model' => $request->validated('model'),
            'app_version' => $request->validated('app_version'),
            'kind' => DeviceKind::Phone,
        ], $request->ip());

        $verificationUrl = route('device.show');

        return response()->json([
            'device_code' => $deviceCode,
            'user_code' => $authorization->user_code,
            'verification_url' => $verificationUrl,
            'verification_url_complete' => $verificationUrl . '?code=' . $authorization->user_code,
            'expires_in' => DeviceFlow::EXPIRES_IN_SECONDS,
            'interval' => DeviceFlow::POLL_INTERVAL_SECONDS,
        ]);
    }
}
