<?php

namespace App\Http\Controllers\Cli;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, ThreadAccess $threadAccess): JsonResponse
    {
        $device = AuthenticateDevice::device($request);
        $user = $device->user()->firstOrFail();

        $lastTeleport = $device->lastTeleportThread;
        $lastTeleportId = $lastTeleport instanceof Thread && $threadAccess->canSee($user, $lastTeleport) ? $lastTeleport->id : null;

        return response()->json([
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'os' => $device->os,
                'arch' => $device->arch,
                'created_at' => $device->created_at?->toIso8601String(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_teleport_thread_id' => $lastTeleportId,
            ],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'protocol' => [
                'current' => (int) config('yak.cli.protocol'),
                'min' => (int) config('yak.cli.min_protocol'),
            ],
            'base_url' => config('app.url'),
        ]);
    }
}
