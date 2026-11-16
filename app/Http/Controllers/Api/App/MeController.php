<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Resources\AppMeData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(AppMeData::from($request->user() ?? abort(401), AuthenticateDevice::device($request)));
    }
}
