<?php

namespace App\Services\Push;

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\ThreadNotificationGuard;

/**
 * Builds what a push may say. Thread payloads go through the guard so a
 * private thread never leaks its title or body.
 */
class PushPayloadFactory
{
    public function __construct(private readonly ThreadNotificationGuard $guard) {}

    /**
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public function forThread(PushEvent $event, Thread $thread, string $body, ?string $tag = null): array
    {
        $payload = $this->guard->pushPayload($thread, $body);

        return [
            'title' => $payload['title'],
            'body' => $payload['body'],
            'url' => route('threads.show', $thread, false),
            'tag' => $tag ?? "thread-{$thread->id}-{$event->value}",
        ];
    }

    /**
     * An account notice carries the account name and the problem, never a thread.
     *
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public function forAccount(ClaudeAccount $account, string $problem): array
    {
        return [
            'title' => $account->name,
            'body' => $problem,
            'url' => '/settings/claude-accounts',
            'tag' => "account-{$account->id}",
        ];
    }
}
