<?php

namespace App\Http\Resources;

use App\Models\PushDevice;
use App\Models\User;
use App\Services\Push\PushEvent;

/**
 * Settings > Notifications props. Mirrored by resources/js/types/notifications.ts.
 */
final class NotificationSettingsData
{
    /**
     * @return array{
     *     events: array<string, bool>,
     *     devices: list<array{id: int, label: ?string, userAgent: ?string, isEnabled: bool, lastUsedAt: ?string, lastError: ?string, isCurrent: bool}>,
     *     vapidPublicKey: ?string,
     *     isIosSupported: bool,
     * }
     */
    public static function from(User $user, ?string $currentEndpoint = null): array
    {
        $events = [];

        foreach (PushEvent::cases() as $event) {
            $events[$event->value] = $user->wantsPush($event);
        }

        return [
            'events' => $events,
            'devices' => array_values(PushDevice::query()->whereMorphedTo('subscribable', $user)->oldest('id')->get()
                ->map(fn (PushDevice $device): array => [
                    'id' => $device->id,
                    'label' => $device->label,
                    'userAgent' => $device->user_agent,
                    'isEnabled' => $device->is_enabled,
                    'lastUsedAt' => $device->last_used_at?->toIso8601String(),
                    'lastError' => $device->last_error,
                    'isCurrent' => $currentEndpoint !== null && $device->endpoint === $currentEndpoint,
                ])->all()),
            'vapidPublicKey' => (is_string($key = config('webpush.vapid.public_key')) && $key !== '') ? $key : null,
            'isIosSupported' => (bool) config('yak.push.ios_supported'),
        ];
    }
}
