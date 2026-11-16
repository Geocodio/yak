<?php

namespace App\Http\Controllers\Cli;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Services\Devices\DeviceRevoker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    public function destroy(Request $request, DeviceRevoker $deviceRevoker): Response
    {
        $deviceRevoker->revoke(AuthenticateDevice::device($request));

        return response()->noContent();
    }
}
