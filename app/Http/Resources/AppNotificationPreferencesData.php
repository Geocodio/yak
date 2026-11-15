<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Push\PushEvent;

/**
 * The person's notification switches for the app: one key per push event, a missing stored key reads as on.
 */
final class AppNotificationPreferencesData
{
    /**
     * @return array{events: array<string, bool>}
     */
    public static function for(User $user): array
    {
        $events = [];

        foreach (PushEvent::cases() as $event) {
            $events[$event->value] = $user->wantsPush($event);
        }

        return ['events' => $events];
    }
}
