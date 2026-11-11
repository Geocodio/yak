<?php

namespace App\Services\Push;

use App\Models\Thread;
use App\Models\User;
use App\Notifications\ThreadPushNotification;
use App\Services\Threads\ThreadNotificationGuard;
use Throwable;

/**
 * The single door for thread pushes. Order: visibility guard, the person's
 * event toggle, presence, then enabled devices.
 */
class ThreadPush
{
    public function __construct(
        private readonly ThreadNotificationGuard $guard,
        private readonly ThreadPresence $presence,
        private readonly PushPayloadFactory $payloads,
    ) {}

    /**
     * @param  iterable<User>  $recipients
     * @return int how many people were pushed
     */
    public function send(PushEvent $event, Thread $thread, iterable $recipients, string $body, ?string $tag = null): int
    {
        $payload = $this->payloads->forThread($event, $thread, $body, $tag);
        $sent = 0;

        foreach ($recipients as $user) {
            if (! $this->guard->mayNotify($user, $thread)
                || ! $user->wantsPush($event)
                || $this->presence->isViewing($user, $thread)
                || $user->routeNotificationForWebPush()->isEmpty()) {
                continue;
            }

            try {
                $user->notify(new ThreadPushNotification($payload));
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }
}
