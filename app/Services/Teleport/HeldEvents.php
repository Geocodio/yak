<?php

namespace App\Services\Teleport;

use App\Channels\Slack\UserLookup;
use App\Enums\HeldEventKind;
use App\Enums\ThreadOwnerSide;
use App\Mail\HeldEventMail;
use App\Models\HeldThreadEvent;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use App\Services\Threads\ThreadNotificationGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Unattended events (CI failures, review comments, summons) never start a turn on a thread
 * a device owns. They wait here until the next teleport up.
 */
class HeldEvents
{
    public function __construct(private readonly ThreadNotificationGuard $guard, private readonly UserLookup $slack) {}

    /**
     * True when the event was held and the caller must stop; false when Yak owns the thread.
     *
     * @param  array<string, mixed>  $payload
     */
    public function holdIfDeviceOwned(YakTask $task, HeldEventKind $kind, string $title, string $summary, array $payload = []): bool
    {
        $thread = $task->thread;

        if ($thread === null || $thread->owner_side !== ThreadOwnerSide::Device) {
            return false;
        }

        $event = $this->record($thread, $kind, $title, $summary, $task->pr_url, $payload, $task->id);
        $this->notify($thread, $event);
        $this->push($thread, $event, app(PushRecipients::class)->forHeldEvent($thread));

        return true;
    }

    /**
     * A child's question escalates to the person when the stack's root sits on a device.
     */
    public function holdChildQuestion(Thread $child, string $question): bool
    {
        $root = $child->rootThread();

        if ($root->owner_side !== ThreadOwnerSide::Device) {
            return false;
        }

        $event = $this->record($root, HeldEventKind::Other, "Question from {$child->title}", $question, null, ['child_thread_id' => $child->id], null);
        $this->notify($root, $event);
        $this->push($root, $event, app(PushRecipients::class)->forChildEscalation($child));

        return true;
    }

    public function tinted(HeldThreadEvent $event): bool
    {
        return $event->created_at !== null
            && $event->created_at->lte(now()->subHours((int) config('yak.teleport.held_event_tint_hours')));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Thread $thread, HeldEventKind $kind, string $title, string $summary, ?string $pullRequestUrl, array $payload, ?int $taskId): HeldThreadEvent
    {
        return HeldThreadEvent::query()->create([
            'thread_id' => $thread->id,
            'kind' => $kind,
            'title' => $title,
            'summary' => $summary,
            'pull_request_url' => $pullRequestUrl,
            'payload' => $payload,
            'task_id' => $taskId,
        ]);
    }

    /**
     * Slack and email only. A private thread's notice carries no event content and no link.
     */
    private function notify(Thread $thread, HeldThreadEvent $event): void
    {
        $owner = User::query()->find($thread->effectiveOwnerUserId());

        if ($owner === null || ! $this->guard->mayNotify($owner, $thread)) {
            return;
        }

        $notice = "An event is waiting on {$this->guard->pushTitle($thread)}. Teleport the thread back to Yak or open it on your computer to handle it.";

        if (! $thread->isPrivate() && $event->pull_request_url !== null) {
            $notice .= "\n{$event->pull_request_url}";
        }

        $this->sendSlack($owner, $notice);

        try {
            Mail::to($owner->email)->send(new HeldEventMail($notice));
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Held event email failed', ['thread_id' => $thread->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  list<User>  $recipients
     */
    private function push(Thread $thread, HeldThreadEvent $event, array $recipients): void
    {
        app(ThreadPush::class)->send(PushEvent::NeedsYou, $thread, $recipients, $event->title, "held-event-{$event->id}");
    }

    private function sendSlack(User $owner, string $notice): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            return;
        }

        try {
            $slackUserId = $this->slack->slackUserIdFor($owner);

            if ($slackUserId !== null) {
                Http::withToken($token)->post('https://slack.com/api/chat.postMessage', ['channel' => $slackUserId, 'text' => $notice]);
            }
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Held event Slack message failed', ['error' => $exception->getMessage()]);
        }
    }
}
