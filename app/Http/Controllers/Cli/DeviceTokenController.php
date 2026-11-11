<?php

namespace App\Http\Controllers\Cli;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cli\PollDeviceTokenRequest;
use App\Services\Devices\DeviceFlow;
use App\Services\Devices\DeviceFlowException;
use Illuminate\Http\JsonResponse;

class DeviceTokenController extends Controller
{
    public function __invoke(PollDeviceTokenRequest $request, DeviceFlow $deviceFlow): JsonResponse
    {
        try {
            ['token' => $token, 'device' => $device] = $deviceFlow->poll($request->validated('device_code'));
        } catch (DeviceFlowException $exception) {
            return response()->json(['error' => $exception->errorCode, 'message' => $exception->getMessage()], 400);
        }

        $user = $device->user()->firstOrFail();

        return response()->json([
            'token' => $token,
            'device' => ['id' => $device->id, 'name' => $device->name, 'os' => $device->os, 'arch' => $device->arch],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }
}
