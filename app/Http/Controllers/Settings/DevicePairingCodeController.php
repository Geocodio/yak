<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceData;
use App\Models\DevicePairingCode;
use App\Services\Devices\PairingCodes;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DevicePairingCodeController extends Controller
{
    public function store(Request $request, PairingCodes $pairingCodes): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        ['pairingCode' => $pairingCode, 'code' => $code] = $pairingCodes->create($user);
        $qrPayload = $pairingCodes->qrPayload($code);

        return response()->json([
            'id' => $pairingCode->id,
            'qrPayload' => $qrPayload,
            'qrSvg' => (new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd)))->writeString($qrPayload),
            'expiresAt' => $pairingCode->expires_at->toIso8601String(),
        ], 201);
    }

    public function show(Request $request, DevicePairingCode $devicePairingCode): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null || $devicePairingCode->user_id !== $user->id, 404);

        $device = $devicePairingCode->device;

        return response()->json([
            'state' => $device !== null ? 'paired' : ($devicePairingCode->isExpired() ? 'expired' : 'waiting'),
            'device' => $device !== null ? DeviceData::from($device, $user) : null,
        ]);
    }
}
