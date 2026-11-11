<?php

namespace App\Services\Push;

use App\Models\Thread;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Pusher\Pusher;
use Throwable;

/**
 * Asks Reverb whether a person has the thread open right now. Any failure
 * counts as not viewing: a push is better than silence.
 */
class ThreadPresence
{
    public function isViewing(User $user, Thread $thread): bool
    {
        try {
            $pusher = $this->pusher();

            if ($pusher === null) {
                return false;
            }

            $response = $pusher->get("/channels/presence-thread-presence.{$thread->id}/users");
            $users = is_array($response) ? ($response['users'] ?? []) : ($response->users ?? []);

            foreach ($users as $member) {
                $id = is_array($member) ? ($member['id'] ?? null) : ($member->id ?? null);

                if ((string) $id === (string) $user->id) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    protected function pusher(): ?Pusher
    {
        $driver = Broadcast::driver();

        return $driver instanceof PusherBroadcaster ? $driver->getPusher() : null;
    }
}
