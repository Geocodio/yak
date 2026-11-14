<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\PairDeviceRequest;
use App\Http\Resources\AppDeviceData;
use App\Services\Devices\DeviceFlowException;
use App\Services\Devices\PairingCodes;
use Illuminate\Http\JsonResponse;

class DevicePairController extends Controller
{
    public function __invoke(PairDeviceRequest $request, PairingCodes $pairingCodes): JsonResponse
    {
        try {
            ['token' => $token, 'device' => $device] = $pairingCodes->redeem($request->validated('code'), [
                'name' => $request->validated('name'),
                'os' => $request->validated('os_version'),
                'model' => $request->validated('model'),
                'app_version' => $request->validated('app_version'),
            ]);
        } catch (DeviceFlowException $exception) {
            return response()->json(['error' => $exception->errorCode, 'message' => $exception->getMessage()], 400);
        }

        return response()->json(AppDeviceData::pairing($device, $token));
    }
}
