<?php

namespace App\Services\Push\Apns;

use App\Models\Thread;
use App\Services\Threads\ThreadNotificationGuard;

/**
 * The bodies of every push, in the shape the app decodes.
 *
 * `turnStartedAt` inside a content state is an ISO 8601 string, never a number: ActivityKit decodes a date as seconds since 2001.
 */
final class ApnsPayloads
{
    private const STALE_AFTER_SECONDS = 1800;

    public function __construct(private readonly ThreadNotificationGuard $guard) {}

    /**
     * @param  array{title: string, body: string, url: string, tag: string, event: string, kind: string, id: ?string}  $push
     * @return array<string, mixed>
     */
    public function alert(array $push, int $needsYouCount): array
    {
        return [
            'aps' => [
                'alert' => ['title' => $push['title'], 'body' => $push['body']],
                'sound' => 'default',
                'badge' => $needsYouCount,
                'thread-id' => $push['kind'] === 'thread' ? "thread-{$push['id']}" : 'account',
                'interruption-level' => $push['event'] === 'needs_you' ? 'time-sensitive' : 'active',
                'filter-criteria' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            ],
            'yak' => [
                'instance' => $this->instance(),
                'event' => $push['event'],
                'kind' => $push['kind'],
                'id' => $push['id'],
                'path' => $push['url'],
                'needsYouCount' => $needsYouCount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $contentState
     * @return array<string, mixed>
     */
    public function liveActivityStart(Thread $thread, array $contentState, string $channelId, int $timestamp): array
    {
        $title = $this->guard->pushTitle($thread);

        return [
            'aps' => [
                'timestamp' => $timestamp,
                'event' => 'start',
                'content-state' => $contentState,
                'attributes-type' => 'ThreadActivityAttributes',
                'attributes' => ['instance' => $this->instance(), 'threadId' => $thread->public_id, 'title' => $title],
                'input-push-channel' => $channelId,
                'stale-date' => $timestamp + self::STALE_AFTER_SECONDS,
                'alert' => ['title' => $title, 'body' => 'Yak is working.'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $contentState
     * @return array<string, mixed>
     */
    public function liveActivityUpdate(array $contentState, int $timestamp): array
    {
        return [
            'aps' => [
                'timestamp' => $timestamp,
                'event' => 'update',
                'content-state' => $contentState,
                'stale-date' => $timestamp + self::STALE_AFTER_SECONDS,
            ],
        ];
    }

    /**
     * The end carries no `stale-date` and no `dismissal-date`, so the system keeps its default time on the lock screen.
     *
     * @param  array<string, mixed>  $contentState
     * @return array<string, mixed>
     */
    public function liveActivityEnd(array $contentState, int $timestamp): array
    {
        return ['aps' => ['timestamp' => $timestamp, 'event' => 'end', 'content-state' => $contentState]];
    }

    /**
     * APNs wants a collapse id of at most 64 bytes; a hash keeps the tag out of the header.
     */
    public static function collapseId(string $tag): string
    {
        return substr(hash('sha256', $tag), 0, 32);
    }

    private function instance(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
