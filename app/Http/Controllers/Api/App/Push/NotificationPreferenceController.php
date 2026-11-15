<?php

namespace App\Http\Controllers\Api\App\Push;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Requests\Api\App\Push\UpdateNotificationPreferencesRequest;
use App\Http\Resources\AppNotificationPreferencesData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(AppNotificationPreferencesData::for(AuthenticateDevice::device($request)->user()->firstOrFail()));
    }

    /**
     * Changes only the switches in the body; the answer is read back from the stored row.
     */
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = AuthenticateDevice::device($request)->user()->firstOrFail();
        $user->setPushPreferences($request->preferences());

        return response()->json(AppNotificationPreferencesData::for($user->refresh()));
    }
}
