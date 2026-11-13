<?php

namespace App\Http\Resources;

use App\Models\Device;
use App\Models\User;
use App\Services\Threads\ThreadAccess;

/**
 * A paired computer on Settings > Devices. Mirrored by resources/js/types/devices.ts.
 */
final class DeviceData
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     os: string,
     *     arch: string,
     *     cli_version: ?string,
     *     paired_at: string,
     *     last_seen_at: ?string,
     *     is_stale: bool,
     *     stale_days: ?int,
     *     last_teleport: ?array{thread_id: string, title: string, at: string},
     *     owned_threads: list<array{id: string, title: string}>,
     * }
     */
    public static function from(Device $device, ?User $viewer): array
    {
        $isStale = $device->isStale();
        $lastActive = $device->last_seen_at ?? $device->created_at;
        $thread = $device->lastTeleportThread;
        $canSeeThread = $thread !== null && $device->last_teleport_at !== null && app(ThreadAccess::class)->canSee($viewer, $thread);

        return [
            'id' => $device->id,
            'name' => $device->name,
            'os' => $device->os,
            'arch' => $device->arch,
            'cli_version' => $device->cli_version,
            'paired_at' => $device->created_at?->toIso8601String() ?? '',
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'is_stale' => $isStale,
            'stale_days' => $isStale && $lastActive !== null ? (int) $lastActive->diffInDays(now()) : null,
            'last_teleport' => $canSeeThread ? [
                'thread_id' => $thread->public_id,
                'title' => $thread->title,
                'at' => $device->last_teleport_at->toIso8601String(),
            ] : null,
            'owned_threads' => array_values($device->ownedThreads->map(fn ($owned): array => ['id' => $owned->public_id, 'title' => $owned->title])->all()),
        ];
    }
}
