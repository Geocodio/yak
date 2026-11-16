<?php

use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Str;

/**
 * Sign a Slack webhook payload using the v0:timestamp:body format.
 *
 * @return array{X-Slack-Request-Timestamp: string, X-Slack-Signature: string}
 */
function signSlackPayload(string $body, string $secret, ?string $timestamp = null): array
{
    $timestamp = $timestamp ?? (string) time();
    $basestring = "v0:{$timestamp}:{$body}";
    $signature = 'v0=' . hash_hmac('sha256', $basestring, $secret);

    return [
        'X-Slack-Request-Timestamp' => $timestamp,
        'X-Slack-Signature' => $signature,
    ];
}

/**
 * Build a Slack thread reply event payload.
 *
 * @param  array<string, mixed>  $overrides
 */
function slackThreadReplyPayload(string $text, string $channel, string $threadTs, array $overrides = []): string
{
    $payload = array_merge([
        'type' => 'event_callback',
        'event_id' => $overrides['event_id'] ?? 'Ev' . Str::random(10),
        'event' => array_merge([
            'type' => 'message',
            'text' => $text,
            'channel' => $channel,
            'thread_ts' => $threadTs,
            'ts' => '1234567899.999999',
            'user' => 'U_REPLY_USER',
        ], $overrides['event'] ?? []),
    ], array_diff_key($overrides, ['event' => true, 'event_id' => true]));

    return (string) json_encode($payload);
}

function heldRequest(array $attributes = [], array $taskAttributes = []): CoOwnerRequest
{
    $repository = Repository::firstOrCreate(['slug' => 'acme/web'], Repository::factory()->raw(['github_full_name' => 'acme/web', 'default_branch' => 'main']));
    $task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => 'acme/web',
        'branch_name' => 'yak/fix-login',
        'author_name' => 'Jane Doe',
        'source' => 'slack',
        ...$taskAttributes,
    ]);

    return CoOwnerRequest::factory()->create([
        'task_id' => $task->id,
        'repository_id' => $repository->id,
        'matched_areas' => [['name' => 'API response pipeline', 'risk' => 'high', 'rationale' => 'It shapes every response.', 'paths' => ['app/Http/Middleware/Authenticate.php', 'app/new.php']]],
        'changed_files' => [
            ['path' => 'app/Http/Middleware/Authenticate.php', 'previousPath' => null, 'status' => 'modified', 'additions' => 12, 'deletions' => 3, 'isHeld' => true],
            ['path' => 'app/new.php', 'previousPath' => null, 'status' => 'new', 'additions' => 30, 'deletions' => 0, 'isHeld' => true],
        ],
        'held_at' => now()->setTime(14, 5),
        ...$attributes,
    ]);
}
