<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\DeviceKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\PollDeviceTokenRequest;
use App\Http\Resources\AppDeviceData;
use App\Services\Devices\DeviceFlow;
use App\Services\Devices\DeviceFlowException;
use Illuminate\Http\JsonResponse;

class DeviceTokenController extends Controller
{
    public function __invoke(PollDeviceTokenRequest $request, DeviceFlow $deviceFlow): JsonResponse
    {
        try {
            ['token' => $token, 'device' => $device] = $deviceFlow->poll($request->validated('device_code'), DeviceKind::Phone, $request->validated('request_id'));
        } catch (DeviceFlowException $exception) {
            return response()->json(['error' => $exception->errorCode, 'message' => $exception->getMessage()], 400);
        }

        return response()->json(AppDeviceData::pairing($device, $token));
    }
}
