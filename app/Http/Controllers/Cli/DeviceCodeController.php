<?php

namespace App\Http\Controllers\Cli;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cli\StartDeviceFlowRequest;
use App\Services\Devices\DeviceFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

class DeviceCodeController extends Controller
{
    public function __invoke(StartDeviceFlowRequest $request, DeviceFlow $deviceFlow): JsonResponse
    {
        /** @var array{name: string, os: string, arch: string, cli_version?: string|null} $reported */
        $reported = $request->validated();
        ['device_code' => $deviceCode, 'authorization' => $authorization] = $deviceFlow->start($reported, $request->ip());

        $verificationUrl = Route::has('device.show') ? route('device.show') : url('/device');

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
